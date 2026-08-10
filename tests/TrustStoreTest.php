<?php

use LSNepomuceno\LaravelA1PdfSign\Contracts\SignatureValidator;
use LSNepomuceno\LaravelA1PdfSign\Facades\A1PdfSign;
use LSNepomuceno\LaravelA1PdfSign\Support\Pem;
use LSNepomuceno\LaravelA1PdfSign\Testing\DebugCertificate;
use LSNepomuceno\LaravelA1PdfSign\Validation\TrustStore;

/**
 * Verifying a chain against the roots the caller named.
 *
 * The package ships no trust store and never will: which authorities to trust
 * is policy and stays with the application. See
 * docs/decisions/0016-trust-is-the-applications-policy.md.
 */
function signedWithTestCertificate(): string
{
    [$pfxPath, $password] = debugCertificate();

    return A1PdfSign::newSignature()
        ->certificate($pfxPath, $password)
        ->pdf(resource('test.pdf'))
        ->sign()
        ->contents;
}

it('reads every certificate out of a bundle', function () {
    $store = TrustStore::fromPem(testCertificate()->original);

    expect($store->isEmpty())->toBeFalse()
        ->and($store->count())->toBe(1)
        ->and($store->certificates[0])->toStartWith(Pem::CERTIFICATE_MARKER);
});

it('keeps one copy of a certificate that appears twice', function () {
    $one = Pem::certificates(testCertificate()->original)[0];

    expect(TrustStore::fromPem($one . "\n" . $one)->count())->toBe(1);
});

it('finds nothing in content that carries no certificate', function () {
    expect(TrustStore::fromPem('not a certificate')->isEmpty())->toBeTrue()
        ->and(TrustStore::empty()->isEmpty())->toBeTrue()
        ->and(TrustStore::empty()->count())->toBe(0);
});

it('answers unknown rather than untrusted when no store was given', function () {
    // The distinction this record exists for: an application that never
    // configured a store must not read "untrusted" and conclude something the
    // run never established.
    $report = app(SignatureValidator::class)->validate(signedWithTestCertificate());

    expect($report->isTrusted())->toBeNull()
        ->and($report->latest()?->isTrusted)->toBeNull()
        // The signature itself still verifies: trust is a further question.
        ->and($report->isValid())->toBeTrue();
});

it('trusts a signature that chains to the root it was given', function () {
    // A real shape: a root authority and a certificate it issued, with the root
    // travelling in the bundle. The plain debug certificate is self-signed and
    // carries basicConstraints CA:FALSE, so a strict verifier will not accept
    // it as its own anchor and is right not to.
    [$pfx, $password, $root] = DebugCertificate::makeChain();

    $path = A1PdfSign::tempPath(true, '.pfx');
    file_put_contents($path, $pfx);

    $signed = A1PdfSign::newSignature()
        ->certificate($path, $password)
        ->pdf(resource('test.pdf'))
        ->sign();

    unlink($path);

    $report = app(SignatureValidator::class)
        ->validate($signed->contents, 'contract.pdf', TrustStore::fromPem($root));

    expect($report->isTrusted())->toBeTrue()
        ->and($report->latest()?->isTrusted)->toBeTrue()
        // The chain the signature carries reaches that root on its own terms
        // too, which is a weaker claim: internally consistent, not trusted.
        ->and($report->latest()?->chainReachesRoot)->toBeTrue();
});

it('refuses a self-signed certificate as its own anchor', function () {
    // Not a defect. openssl_x509_checkpurpose builds a path and a certificate
    // that says CA:FALSE cannot be one, so accepting it would be laxer than
    // any reader the document will meet.
    $report = app(SignatureValidator::class)->validate(
        signedWithTestCertificate(),
        'contract.pdf',
        TrustStore::fromPem(testCertificate()->original),
    );

    expect($report->isTrusted())->toBeFalse();
});

it('does not trust a signature against an unrelated root', function () {
    [$otherPfx, $otherPassword] = DebugCertificate::make();
    $other = app(\LSNepomuceno\LaravelA1PdfSign\Certificates\NativeCertificateReader::class)
        ->read($otherPfx, $otherPassword);

    $report = app(SignatureValidator::class)->validate(
        signedWithTestCertificate(),
        'contract.pdf',
        TrustStore::fromPem($other->original),
    );

    expect($report->isTrusted())->toBeFalse()
        ->and($report->latest()?->isTrusted)->toBeFalse()
        // Untrusted is not invalid. The signature still matches the bytes.
        ->and($report->isValid())->toBeTrue();
});

it('trusts nothing when the store is empty, which is not the same as unknown', function () {
    $report = app(SignatureValidator::class)->validate(
        signedWithTestCertificate(),
        'contract.pdf',
        TrustStore::empty(),
    );

    expect($report->isTrusted())->toBeFalse();
});

it('reads a bundle from a file and from a directory', function () {
    // A directory is read and concatenated rather than handed to OpenSSL as a
    // CA path: that form needs the hashed symlinks c_rehash creates, and a
    // directory of plain files silently verifies nothing.
    $directory = A1PdfSign::tempPath() . '-trust';
    @mkdir($directory, 0o755, true);

    // One certificate per extension a CA bundle ships under, and one file that
    // is not a certificate at all: authorities publish notes and hash files
    // beside the bundle, and reading them would put junk into the roots.
    //
    // Each testCertificate() call generates a fresh certificate, so the count is
    // three only if all three extensions were read: identical bytes would be
    // deduplicated and the assertion would pass on one file alone.
    $files = [
        'root.pem' => testCertificate()->original,
        'intermediate.crt' => testCertificate()->original,
        'other.cer' => testCertificate()->original,
        'README.txt' => 'not a certificate',
    ];

    foreach ($files as $name => $contents) {
        file_put_contents("{$directory}/{$name}", $contents);
    }

    expect(TrustStore::fromFile("{$directory}/root.pem")->count())->toBe(1)
        ->and(TrustStore::fromDirectory($directory)->count())->toBe(3);

    foreach (array_keys($files) as $name) {
        unlink("{$directory}/{$name}");
    }

    rmdir($directory);
});

it('reports trust through the facade', function () {
    [$pfx, $password, $root] = DebugCertificate::makeChain();

    $pfxPath = A1PdfSign::tempPath(true, '.pfx');
    file_put_contents($pfxPath, $pfx);

    $signed = A1PdfSign::newSignature()
        ->certificate($pfxPath, $password)
        ->pdf(resource('test.pdf'))
        ->sign();

    $path = A1PdfSign::tempPath(true, '.pdf');
    $signed->save($path);

    expect(A1PdfSign::validate($path, TrustStore::fromPem($root))->isTrusted())->toBeTrue()
        // And without a store, the same document answers unknown.
        ->and(A1PdfSign::validate($path)->isTrusted())->toBeNull();

    unlink($pfxPath);
    unlink($path);
});

it('round-trips a certificate through DER and back', function () {
    $pem = Pem::certificates(testCertificate()->original)[0];
    $der = Pem::toDer($pem);

    expect($der)->not->toBeNull()
        ->and(Pem::toDer(Pem::fromDer((string) $der)))->toBe($der)
        ->and(Pem::hasCertificate($pem))->toBeTrue()
        ->and(Pem::hasCertificate('nothing here'))->toBeFalse();
});

it('answers null for armour whose body is not base64', function () {
    expect(Pem::toDer(Pem::CERTIFICATE_MARKER . "\n!!!!\n-----END CERTIFICATE-----"))->toBeNull()
        ->and(Pem::toDer(Pem::CERTIFICATE_MARKER . "\n-----END CERTIFICATE-----"))->toBeNull();
});
