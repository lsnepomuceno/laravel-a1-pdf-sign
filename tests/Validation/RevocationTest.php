<?php

declare(strict_types=1);

use LSNepomuceno\LaravelA1PdfSign\Enums\RevocationStatus;
use LSNepomuceno\LaravelA1PdfSign\Support\Files;
use LSNepomuceno\LaravelA1PdfSign\Validation\RevocationChecker;

/**
 * Reading the revocation material a document carries, RFC 6960 and RFC 5280.
 *
 * The Document Security Store has been written since 2.0 and counted since 2.2:
 * the report said how many OCSP responses were there and nothing said what any
 * of them meant, so a document could carry a responder's word that its signer
 * was revoked and still report as valid.
 *
 * The fixtures under tests/Resources/revocation are produced by OpenSSL itself,
 * not by this package: a CA, a leaf with serial 0x1234, and a good and a revoked
 * answer of each kind. `openssl ocsp -respin` reports "Response verify OK" and
 * "revoked" for the revoked one.
 *
 * See docs/decisions/0024-revocation-is-evaluated-not-counted.md.
 */
function revocationFixture(string $name): string
{
    return Files::read(resource('revocation/' . $name));
}

/**
 * @return list<string>
 */
function revocationIssuer(): array
{
    return [revocationFixture('ca.pem')];
}

/** The serial the fixtures were issued with, as openssl_x509_parse reports it. */
const PROBE_SERIAL = '1234';

it('reads a good answer out of an OCSP response', function () {
    $checker = new RevocationChecker();

    expect($checker->status(PROBE_SERIAL, [revocationFixture('ocsp-good.der')], [], revocationIssuer()))
        ->toBe(RevocationStatus::Good);
});

it('reads a revoked answer out of an OCSP response', function () {
    $checker = new RevocationChecker();

    expect($checker->status(PROBE_SERIAL, [revocationFixture('ocsp-revoked.der')], [], revocationIssuer()))
        ->toBe(RevocationStatus::Revoked);
});

it('reads a good answer out of a CRL', function () {
    // A CRL that lists nothing, or lists other serials, is a positive statement
    // that this one is not revoked rather than an absence of information.
    $checker = new RevocationChecker();

    expect($checker->status(PROBE_SERIAL, [], [revocationFixture('crl-good.der')], revocationIssuer()))
        ->toBe(RevocationStatus::Good);
});

it('reads a revoked answer out of a CRL', function () {
    $checker = new RevocationChecker();

    expect($checker->status(PROBE_SERIAL, [], [revocationFixture('crl-revoked.der')], revocationIssuer()))
        ->toBe(RevocationStatus::Revoked);
});

it('lets one revocation outweigh any number of good answers', function () {
    // A responder saying a certificate is revoked is not something a second
    // opinion undoes.
    $checker = new RevocationChecker();

    expect($checker->status(
        PROBE_SERIAL,
        [revocationFixture('ocsp-good.der')],
        [revocationFixture('crl-revoked.der')],
        revocationIssuer(),
    ))->toBe(RevocationStatus::Revoked);
});

it('believes nothing that does not verify against the issuer', function () {
    // Material is evidence only if it is signed by the authority it claims to
    // come from. Unrelated roots must make it worthless, not merely unmatched.
    $checker = new RevocationChecker();
    $stranger = [Files::read(resource('revocation/leaf.pem'))];

    expect($checker->status(PROBE_SERIAL, [revocationFixture('ocsp-revoked.der')], [], $stranger))
        ->toBe(RevocationStatus::Unknown)
        ->and($checker->status(PROBE_SERIAL, [], [revocationFixture('crl-revoked.der')], $stranger))
        ->toBe(RevocationStatus::Unknown);
});

it('refuses material that has been altered', function () {
    // One byte flipped inside the signed part, which is what a signature is for.
    $checker = new RevocationChecker();
    $crl = revocationFixture('crl-revoked.der');
    $tampered = substr_replace($crl, chr((ord($crl[40]) ^ 0xFF) & 0xFF), 40, 1);

    expect($checker->status(PROBE_SERIAL, [], [$tampered], revocationIssuer()))
        ->toBe(RevocationStatus::Unknown);
});

it('says unknown for a serial the material does not mention', function () {
    // A response about somebody else is not an answer about this signer.
    $checker = new RevocationChecker();

    expect($checker->status('DEADBEEF', [revocationFixture('ocsp-good.der')], [], revocationIssuer()))
        ->toBe(RevocationStatus::Unknown);
});

it('says unknown when there is nothing to read', function () {
    $checker = new RevocationChecker();

    expect($checker->status(PROBE_SERIAL, [], [], revocationIssuer()))->toBe(RevocationStatus::Unknown)
        ->and($checker->status(PROBE_SERIAL, ['not der'], ['not der'], revocationIssuer()))
        ->toBe(RevocationStatus::Unknown);
});

it('distinguishes an answer from the absence of one', function () {
    expect(RevocationStatus::Good->isKnown())->toBeTrue()
        ->and(RevocationStatus::Revoked->isKnown())->toBeTrue()
        ->and(RevocationStatus::Unknown->isKnown())->toBeFalse();
});

it('pulls the material out of a document security store', function () {
    // The entries are indirect references to streams, so answering with them
    // means resolving the reference and decoding the payload rather than
    // counting the references, which is all the report did before.
    $ocsp = revocationFixture('ocsp-good.der');
    $crl = revocationFixture('crl-good.der');

    $pdf = pdfWith([
        1 => '<</Type/Catalog/Pages 2 0 R/DSS 5 0 R>>',
        2 => '<</Type/Pages/Kids[3 0 R]/Count 1>>',
        3 => '<</Type/Page/Parent 2 0 R/MediaBox[0 0 595 842]>>',
        4 => '<</Length ' . strlen($ocsp) . ">>\nstream\n" . $ocsp . "\nendstream",
        5 => '<</Type /DSS /OCSPs [4 0 R] /CRLs [6 0 R]>>',
        6 => '<</Length ' . strlen($crl) . ">>\nstream\n" . $crl . "\nendstream",
    ]);

    $material = app(LSNepomuceno\LaravelA1PdfSign\Validation\RevocationReader::class)->material($pdf);

    expect($material['ocsp'])->toBe([$ocsp])
        ->and($material['crls'])->toBe([$crl]);

    // And read back through the checker, which is the whole point of reading it.
    expect(new RevocationChecker()->status(
        PROBE_SERIAL,
        $material['ocsp'],
        $material['crls'],
        revocationIssuer(),
    ))->toBe(RevocationStatus::Good);
});

it('finds nothing in a document that carries no store', function () {
    $material = app(LSNepomuceno\LaravelA1PdfSign\Validation\RevocationReader::class)
        ->material(Files::read(resource('test.pdf')));

    expect($material)->toBe(['ocsp' => [], 'crls' => []]);
});

it('skips an entry the document no longer resolves', function () {
    // A store may name an object a later revision removed. One fewer piece of
    // evidence, not a reason to abandon the rest.
    $crl = revocationFixture('crl-revoked.der');

    $pdf = pdfWith([
        1 => '<</Type/Catalog/Pages 2 0 R/DSS 4 0 R>>',
        2 => '<</Type/Pages/Kids[3 0 R]/Count 1>>',
        3 => '<</Type/Page/Parent 2 0 R/MediaBox[0 0 595 842]>>',
        4 => '<</Type /DSS /OCSPs [99 0 R] /CRLs [5 0 R]>>',
        5 => '<</Length ' . strlen($crl) . ">>\nstream\n" . $crl . "\nendstream",
    ]);

    $material = app(LSNepomuceno\LaravelA1PdfSign\Validation\RevocationReader::class)->material($pdf);

    expect($material['ocsp'])->toBe([])
        ->and($material['crls'])->toBe([$crl]);
});

it('reports unknown for a document whose store carries no revocation material', function () {
    // The B-LT sample embeds the chain and nothing else, because the debug
    // certificate is self-signed with no responder and no distribution point.
    $report = app(LSNepomuceno\LaravelA1PdfSign\Contracts\SignatureValidator::class)
        ->validate(Files::read(sample('pades-b-lt.pdf')));

    expect($report->latest()?->revocation)->toBe(RevocationStatus::Unknown)
        ->and($report->latest()?->isRevoked())->toBeFalse()
        // Unknown revocation is not an invalid signature: the two answer
        // different questions.
        ->and($report->isValid())->toBeTrue();
});
