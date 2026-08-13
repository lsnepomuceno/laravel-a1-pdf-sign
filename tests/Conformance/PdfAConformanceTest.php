<?php

declare(strict_types=1);

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

it('draws the seal in a colour space it carries itself', function () {
    // §6.2.3.3 for PDF/A-1 and §6.2.4.3 for PDF/A-2 allow DeviceRGB only where
    // the file carries an RGB OutputIntent, and adding one is the author's
    // statement about their document rather than the signer's. An ICCBased
    // space brings its own profile and asks the document for nothing
    // (docs/decisions/0028-the-seal-carries-its-own-colour-space.md).
    [$pfxPath, $password] = debugCertificate();

    $signed = A1PdfSign::newSignature()
        ->certificate($pfxPath, $password)
        ->pdf(resource('pdfa-2b.pdf'))
        ->seal(placement: new SealPlacement(x: 60, y: 400, width: 120))
        ->sign();

    // Only the appended revision: the document's own content already uses an
    // ICCBased space, which is how it was conformant to begin with.
    $appended = substr($signed->contents, strlen(Files::read(resource('pdfa-2b.pdf'))));

    expect($appended)->toMatch('#/ColorSpace\[/ICCBased \d+ 0 R\]#')
        ->and($appended)->not->toContain('/ColorSpace/DeviceRGB')
        // The profile object itself, and /N 3 is what makes it three-component.
        ->and($appended)->toContain('<</N 3/Filter/FlateDecode');
});

it('leaves the colour space alone when there is no seal to draw', function () {
    // An invisible signature draws nothing, so it embeds no profile: 2.4 KB
    // added to every signed document for a colour nobody sees would be a cost
    // with no benefit.
    [$pfxPath, $password] = debugCertificate();

    $signed = A1PdfSign::newSignature()
        ->certificate($pfxPath, $password)
        ->pdf(resource('pdfa-2b.pdf'))
        ->sign();

    $appended = substr($signed->contents, strlen(Files::read(resource('pdfa-2b.pdf'))));

    expect($appended)->not->toContain('/ICCBased');
});

it('gives the page a transparency group only when the seal is transparent', function () {
    // ISO 19005-2 §6.2.10: a page carrying transparency needs a group naming
    // the blending colour space, unless an OutputIntent answers for it. It is
    // the only rule left standing between a transparent seal and PDF/A-2, and
    // veraPDF named it by number.
    [$pfxPath, $password] = debugCertificate();

    $transparent = A1PdfSign::newSignature()
        ->certificate($pfxPath, $password)
        ->pdf(resource('pdfa-2b.pdf'))
        ->seal(placement: new SealPlacement(x: 60, y: 400, width: 120))
        ->sign();

    config()->set('a1-pdf-sign.seal.transparent', false);

    $opaque = A1PdfSign::newSignature()
        ->certificate($pfxPath, $password)
        ->pdf(resource('pdfa-2b.pdf'))
        ->seal(placement: new SealPlacement(x: 60, y: 400, width: 120))
        ->sign();

    expect($transparent->contents)->toMatch('#/Group<</S/Transparency/CS\[/ICCBased \d+ 0 R\]>>#')
        // An opaque seal puts no transparency on the page, so the group would
        // be a claim about the document that signing did not make true.
        ->and(substr($opaque->contents, strlen(Files::read(resource('pdfa-2b.pdf')))))
        ->not->toContain('/Group');
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

it('gives a page that receives a widget the tab order accessibility requires', function () {
    // ISO 14289-1 7.18.3: every page on which there is an annotation shall
    // contain /Tabs in its page dictionary, with the value S. A signature
    // widget is an annotation, so appending one puts the page under the rule
    // (docs/decisions/0032-what-signing-does-to-pdf-ua.md).
    //
    // Here as well as in the veraPDF group because this one runs where no JRE
    // exists, which is the division tests/Conformance/PdfAValidationTest.php describes.
    [$pfxPath, $password] = debugCertificate();

    $signed = A1PdfSign::newSignature()
        ->certificate($pfxPath, $password)
        ->pdf(resource('test.pdf'))
        ->sign();

    $appended = substr($signed->contents, strlen(Files::read(resource('test.pdf'))));

    expect($appended)->toContain('/Tabs/S');
});

it('leaves a tab order the document already declared alone', function () {
    // /S is what accessibility asks for, and /R and /C are legitimate choices a
    // producer makes about their own document. Overwriting one would be the
    // signer deciding how somebody else's page is navigated, and a document
    // arriving as PDF/UA already carries /S.
    [$pfxPath, $password] = debugCertificate();

    // Built rather than patched into the committed fixture: changing a
    // dictionary's length shifts every offset after it, and the cross-reference
    // table would then point into the middle of objects.
    $original = pdfWith([
        1 => '<</Type/Catalog/Pages 2 0 R>>',
        2 => '<</Type/Pages/Kids[3 0 R]/Count 1>>',
        3 => '<</Type/Page/Parent 2 0 R/MediaBox[0 0 595 842]/Tabs/R>>',
    ]);

    $source = A1PdfSign::tempPath(true, '.pdf');

    file_put_contents($source, $original);

    $signed = A1PdfSign::newSignature()
        ->certificate($pfxPath, $password)
        ->pdf($source)
        ->sign();

    $appended = substr($signed->contents, strlen($original));

    expect($appended)->toContain('/Tabs/R')
        ->and($appended)->not->toContain('/Tabs/S');
});
