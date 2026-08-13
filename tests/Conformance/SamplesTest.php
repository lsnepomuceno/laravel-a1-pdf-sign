<?php

declare(strict_types=1);

use LSNepomuceno\LaravelA1PdfSign\Facades\A1PdfSign;
use LSNepomuceno\LaravelA1PdfSign\Support\Files;

/**
 * The committed samples are this package's own output, kept for validation in
 * real readers. That only means anything while they are **this version's**
 * output.
 *
 * They went stale for a whole release and nothing noticed: 2.4 made the seal
 * transparent and carried the trailer /ID into the revision, and every sample
 * in `samples/` still showed the 2.3 shape. The suite read them the whole time,
 * for chains, timestamps and structure, so it was reading old evidence and
 * agreeing with itself.
 *
 * Byte comparison is not available: signing embeds a time and a throwaway
 * certificate, so no two runs agree. What can be checked is that the samples
 * carry the structures the current signer writes, which is what would have
 * caught it.
 *
 * Regenerate with `php poc/sign-samples.php` and copy `.output` over
 * `samples/`.
 */

/**
 * Every committed sample that carries a visible seal.
 *
 * @return list<string>
 */
function sealedSamples(): array
{
    return ['legacy.pdf', 'pades-b-b.pdf', 'pades-b-t.pdf', 'pades-b-lt.pdf', 'pades-b-lta.pdf', 'two-seals.pdf'];
}

it('shows a seal drawn in the colour space this version writes', function (string $name) {
    // 0028: the seal carries its own ICCBased profile, so it stops costing
    // PDF/A conformance. A sample still showing /DeviceRGB was produced by an
    // older signer.
    $contents = Files::read(sample($name));

    expect($contents)->toMatch('#/ColorSpace\[/ICCBased \d+ 0 R\]#')
        ->and($contents)->not->toContain('/ColorSpace/DeviceRGB');
})->with(sealedSamples());

it('shows the transparency this version honours by default', function (string $name) {
    // 0023: seal.transparent defaults to true, so the artwork's alpha travels
    // as an /SMask instead of being flattened. No sample carried one until they
    // were regenerated, which is the shape of the staleness this file exists
    // to catch.
    expect(Files::read(sample($name)))->toContain('/SMask');
})->with(sealedSamples());

it('claims no file identifier the source document never had', function (string $name) {
    // The other half of the 2.4 /ID fix, and the half a sample can show: the
    // revision carries the identifier forward when there is one, and invents
    // none when there is not. Every sample descends from tests/Resources/
    // test.pdf, whose trailer has no /ID, so inventing one here would be
    // claiming an identity for a document this only appended to
    // (docs/decisions/0025-what-signing-does-to-pdf-a.md).
    expect(Files::read(sample($name)))->not->toContain('/ID');
})->with(['pades-b-b.pdf', 'six-signatures.pdf', 'certified.pdf']);

it('gives the archive timestamp an appearance dictionary', function () {
    // The fault 0025 found by reading a committed sample rather than by running
    // anything: Timestamp2 had /Rect[0 0 0 0] and no /AP at all, beside a
    // signature widget that had one.
    expect(Files::read(sample('pades-b-lta.pdf')))
        ->toMatch('#/Rect\[0 0 0 0\]/AP<</N \d+ 0 R>>/T \(Timestamp#');
});

it('still validates every sample it ships', function (string $name) {
    // The samples are evidence, so a sample this package cannot read back is
    // worse than no sample at all.
    $report = A1PdfSign::validate(sample($name));

    expect($report->isSigned())->toBeTrue()
        ->and($report->isValid())->toBeTrue();
})->with([
    'legacy.pdf',
    'pades-b-b.pdf',
    'pades-b-t.pdf',
    'pades-b-lt.pdf',
    'pades-b-lta.pdf',
    'two-seals.pdf',
    'six-signatures.pdf',
    'certified.pdf',
    'signed-into-fields.pdf',
    'xref-stream.pdf',
    'object-stream.pdf',
]);
