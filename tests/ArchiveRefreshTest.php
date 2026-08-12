<?php

declare(strict_types=1);

use LSNepomuceno\LaravelA1PdfSign\Contracts\SignatureTransport;
use LSNepomuceno\LaravelA1PdfSign\Enums\SignatureProfile;
use LSNepomuceno\LaravelA1PdfSign\Facades\A1PdfSign;
use LSNepomuceno\LaravelA1PdfSign\Signing\ArchiveExtender;
use LSNepomuceno\LaravelA1PdfSign\Support\Files;
use LSNepomuceno\LaravelA1PdfSign\Support\ProcessRunner;
use LSNepomuceno\LaravelA1PdfSign\Testing\DebugCertificate;
use LSNepomuceno\LaravelA1PdfSign\Testing\LocalRevocationAuthority;
use LSNepomuceno\LaravelA1PdfSign\Validation\SecurityStoreReader;

/**
 * Extending an archive refreshes the evidence it archives.
 *
 * [0022](docs/decisions/0022-the-archive-timestamp-is-a-chain.md) built the
 * chain and said outright what it left undone: "nothing refreshes the Document
 * Security Store while extending". So a document could gain a fifth archive
 * timestamp over revocation material gathered on the day it was signed, years
 * earlier, which is the one thing long-term validation exists to prevent.
 *
 * ETSI EN 319 142-1 puts the order the other way round: the material for
 * everything the document already carries goes in **first**, while it is still
 * verifiable, and the archive timestamp then covers it.
 */
beforeEach(function () {
    app()->bind(SignatureTransport::class, fn(): LocalRevocationAuthority => new LocalRevocationAuthority(
        app(ProcessRunner::class),
        crl: Files::read(resource('revocation/crl-good.der')),
    ));

    config()->set('a1-pdf-sign.signature.timestamp.url', 'https://timestamp.invalid/tsr');
});

/**
 * A B-LT document signed with a certificate that names where its revocation
 * material lives, so the store is not empty to begin with.
 */
function archivedDocument(): string
{
    [$pfx, $password] = DebugCertificate::makeRevocable();

    $path = A1PdfSign::tempPath(true, '.pfx');
    file_put_contents($path, $pfx);

    $signed = A1PdfSign::newSignature()
        ->certificate($path, $password)
        ->pdf(resource('test.pdf'))
        ->profile(SignatureProfile::PadesBLTA)
        ->sign();

    unlink($path);

    return $signed->contents;
}

it('gathers revocation material a certificate points at', function () {
    // The precondition for everything below, and the reason
    // DebugCertificate::makeRevocable() exists: collectValidationMaterial reads
    // the endpoints out of the certificate, so one carrying none is never asked
    // about, whatever transport is bound.
    $store = app(SecurityStoreReader::class)->read(archivedDocument());

    expect($store->crls)->toBeGreaterThan(0);
});

it('appends a further store when the archive is extended', function () {
    $archived = archivedDocument();
    $before = app(SecurityStoreReader::class)->read($archived)->certificates;

    $extended = app(ArchiveExtender::class)->extend($archived);
    $after = app(SecurityStoreReader::class)->read($extended->contents)->certificates;

    // The reader answers with the newest store, and a refreshed one carries the
    // certificates of every signature and timestamp in the file rather than
    // only the signer's.
    expect($after)->toBeGreaterThan($before);
});

it('writes the store before the timestamp that has to cover it', function () {
    // The ordering is the whole correctness claim. A store appended after the
    // archive timestamp is material the timestamp does not attest, which is
    // worth no more than material sitting outside the file.
    $extended = app(ArchiveExtender::class)->extend(archivedDocument())->contents;

    $store = strrpos($extended, '/DSS');
    $timestamp = strrpos($extended, '/DocTimeStamp');

    expect($store)->toBeInt()
        ->and($timestamp)->toBeInt()
        ->and((int) $store)->toBeLessThan((int) $timestamp);
});

it('keeps every signature valid through the refresh', function () {
    // Two revisions are appended where one used to be, and the invariant is
    // unchanged: the original bytes survive and nothing already signed moves.
    $archived = archivedDocument();
    $extended = app(ArchiveExtender::class)->extend($archived)->contents;

    expect(substr($extended, 0, strlen($archived)))->toBe($archived);

    $report = A1PdfSign::validate($path = tap(A1PdfSign::tempPath(true, '.pdf'), function (string $to) use ($extended) {
        file_put_contents($to, $extended);
    }));

    expect($report->isValid())->toBeTrue()
        ->and($report->timestamps())->toHaveCount(2);

    unlink($path);
});

it('carries the timestamp authority chain the signer store never had', function () {
    // Worth having even for a certificate with no responder and no distribution
    // point, which is what a self-signed one is. The store written at signing
    // time holds the signer's chain; the authority that stamped the document has
    // a certificate of its own, and it is the one the *next* archive timestamp
    // has to be able to check.
    app()->bind(
        SignatureTransport::class,
        fn(): LocalRevocationAuthority => new LocalRevocationAuthority(app(ProcessRunner::class)),
    );

    [$pfxPath, $password] = debugCertificate();

    $signed = A1PdfSign::newSignature()
        ->certificate($pfxPath, $password)
        ->pdf(resource('test.pdf'))
        ->profile(SignatureProfile::PadesBLTA)
        ->sign();

    $before = app(SecurityStoreReader::class)->read($signed->contents)->certificates;
    $extended = app(ArchiveExtender::class)->extend($signed->contents)->contents;
    $after = app(SecurityStoreReader::class)->read($extended)->certificates;

    expect($after)->toBeGreaterThan($before);
});
