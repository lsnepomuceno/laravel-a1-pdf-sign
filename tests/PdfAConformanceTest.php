<?php

use LSNepomuceno\LaravelA1PdfSign\Contracts\SignatureValidator;
use LSNepomuceno\LaravelA1PdfSign\Data\SealPlacement;
use LSNepomuceno\LaravelA1PdfSign\Facades\A1PdfSign;
use LSNepomuceno\LaravelA1PdfSign\Signing\Incremental\DocumentReader;
use LSNepomuceno\LaravelA1PdfSign\Support\Files;

/**
 * What signing does to a PDF/A document, ISO 19005.
 *
 * The fixtures are produced by Ghostscript and confirmed conformant by veraPDF
 * 1.30.2 before anything is done to them. veraPDF is Java and is not available
 * in CI, so the conformance verdicts themselves live in
 * docs/decisions/0025-what-signing-does-to-pdf-a.md; what runs here is the
 * structure each verdict turned on, so a change that would break conformance
 * fails in the suite rather than in someone's archive.
 */
it('signs a PDF/A document without disturbing what makes it one', function (string $part) {
    // Signing appends a revision, so the metadata and the page tree survive
    // byte for byte. That is invariant 2, checked here against the parts a
    // PDF/A validator actually reads.
    [$pfxPath, $password] = debugCertificate();
    $original = Files::read(resource("pdfa-{$part}.pdf"));

    $signed = A1PdfSign::newSignature()
        ->certificate($pfxPath, $password)
        ->pdf(resource("pdfa-{$part}.pdf"))
        ->sign();

    expect(substr($signed->contents, 0, strlen($original)))->toBe($original)
        // The XMP packet that declares the conformance level is untouched.
        ->and($signed->contents)->toContain('pdfaid')
        ->and(app(SignatureValidator::class)->validate($signed->contents)->isValid())->toBeTrue();
})->with(['1b', '2b']);

it('carries the file identifier into the revision it appends', function (string $part) {
    // ISO 32000-1 §14.4: /ID identifies the file and belongs in every trailer.
    // Dropping it is what made a signed PDF/A document fail §6.1.3, and it
    // costs a document its identity for every other reader besides.
    [$pfxPath, $password] = debugCertificate();

    $document = app(DocumentReader::class)->read(Files::read(resource("pdfa-{$part}.pdf")));

    expect($document->id)->not->toBeNull();

    $signed = A1PdfSign::newSignature()
        ->certificate($pfxPath, $password)
        ->pdf(resource("pdfa-{$part}.pdf"))
        ->sign();

    // Both trailers, the original and the appended one, and the same pair.
    expect(substr_count($signed->contents, '/ID ' . $document->id))->toBeGreaterThanOrEqual(2)
        ->and(app(DocumentReader::class)->read($signed->contents)->id)->toBe($document->id);
})->with(['1b', '2b']);

it('gives an invisible signature an appearance, because a form field needs one', function () {
    // ISO 19005-1 §6.9: every form field shall have an appearance dictionary.
    // A signature with no seal is still a form field, and a zero box draws
    // nothing, which is what invisible means.
    [$pfxPath, $password] = debugCertificate();

    $signed = A1PdfSign::newSignature()
        ->certificate($pfxPath, $password)
        ->pdf(resource('test.pdf'))
        ->sign();

    expect($signed->contents)->toContain('/Rect[0 0 0 0]')
        ->toMatch('#/AP<</N \d+ 0 R>>#')
        ->toContain('/BBox[0 0 0 0]');
});

it('leaves the document without an /ID alone rather than inventing one', function () {
    // A file identifier is the producer's, and a signer that made one up would
    // be claiming an identity for a document it only appended to.
    [$pfxPath, $password] = debugCertificate();

    $signed = A1PdfSign::newSignature()
        ->certificate($pfxPath, $password)
        ->pdf(resource('test.pdf'))
        ->sign();

    expect(app(DocumentReader::class)->read(Files::read(resource('test.pdf')))->id)->toBeNull()
        ->and($signed->contents)->not->toContain('/ID');
});

it('draws the seal in DeviceRGB, which is what costs PDF/A conformance', function () {
    // Measured rather than assumed: with a seal, veraPDF fails both parts on
    // DeviceRGB (§6.2.3.3 for PDF/A-1, §6.2.4.3 for PDF/A-2), because the
    // colour space is allowed only where the file carries an RGB OutputIntent.
    // Asserted here so the day the seal moves to an ICCBased space, this test
    // is what says so.
    [$pfxPath, $password] = debugCertificate();

    $signed = A1PdfSign::newSignature()
        ->certificate($pfxPath, $password)
        ->pdf(resource('pdfa-2b.pdf'))
        ->seal(placement: new SealPlacement(x: 60, y: 400, width: 120))
        ->sign();

    // Only the appended revision: the document's own content already uses an
    // ICCBased space, which is how it was conformant to begin with.
    $appended = substr($signed->contents, strlen(Files::read(resource('pdfa-2b.pdf'))));

    expect($appended)->toContain('/ColorSpace/DeviceRGB')
        ->and($appended)->not->toContain('/ICCBased');
});

it('puts an /SMask in the file only when the seal is transparent', function () {
    // ISO 19005-1 §6.4: an XObject dictionary shall not contain the SMask key.
    // A transparent seal can therefore never be PDF/A-1 conformant, and
    // seal.transparent is the lever that decides it.
    [$pfxPath, $password] = debugCertificate();

    $transparent = A1PdfSign::newSignature()
        ->certificate($pfxPath, $password)
        ->pdf(resource('pdfa-1b.pdf'))
        ->seal(placement: new SealPlacement(x: 60, y: 400, width: 120))
        ->sign();

    config()->set('a1-pdf-sign.seal.transparent', false);

    $opaque = A1PdfSign::newSignature()
        ->certificate($pfxPath, $password)
        ->pdf(resource('pdfa-1b.pdf'))
        ->seal(placement: new SealPlacement(x: 60, y: 400, width: 120))
        ->sign();

    expect($transparent->contents)->toContain('/SMask')
        ->and($opaque->contents)->not->toContain('/SMask');
});
