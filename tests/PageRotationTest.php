<?php

use LSNepomuceno\LaravelA1PdfSign\Data\SealPlacement;
use LSNepomuceno\LaravelA1PdfSign\Facades\A1PdfSign;
use LSNepomuceno\LaravelA1PdfSign\Signing\Incremental\PageGeometry;

/**
 * A page as the reader shows it, against a page as its coordinates describe it.
 *
 * ISO 32000-1 §7.7.3.3: `/Rotate` turns the page clockwise for display and
 * leaves the coordinate system where it was. `/Rotate 90` is how most scanners
 * and many generators express landscape, so this is not an exotic input.
 *
 * The placement went straight into `/Rect`, and `grep -rn Rotate src/` returned
 * nothing at all. Measured before the fix, at `/Rotate 90` with a placement of
 * (60, 400): `Rect[60 400 180 460]`, the caller's numbers untouched, which puts
 * the seal somewhere else entirely on screen and draws it sideways.
 *
 * Confirmed afterwards with poppler, rendering both pages at 40 dpi and finding
 * the ink: the rotated page carries the seal at 7-21% across and 24-33% down,
 * which is where a placement of (60, 400) sits on a page displayed 842 wide and
 * 595 tall.
 */

/**
 * A one-page document with the given rotation, and nothing else on it.
 */
function pageRotated(?int $rotate, string $mediaBox = '[0 0 595 842]'): string
{
    return pdfWith([
        1 => '<</Type/Catalog/Pages 2 0 R>>',
        2 => '<</Type/Pages/Kids[3 0 R]/Count 1>>',
        3 => '<</Type/Page/Parent 2 0 R/MediaBox' . $mediaBox
            . ($rotate === null ? '' : "/Rotate {$rotate}") . '>>',
    ]);
}

/**
 * Signs a document with a seal at a known place and returns its /Rect.
 *
 * @return list<float>
 */
function sealedRectangle(string $pdf): array
{
    [$pfxPath, $password] = debugCertificate();

    $path = A1PdfSign::tempPath(true, '.pdf');
    file_put_contents($path, $pdf);

    $signed = A1PdfSign::newSignature()
        ->certificate($pfxPath, $password)
        ->pdf($path)
        ->seal(placement: new SealPlacement(x: 60, y: 400, width: 120, height: 60))
        ->sign()
        ->contents;

    preg_match('/\/Rect\[([^\]]+)\]/', $signed, $found);

    $parts = preg_split('/\s+/', trim($found[1] ?? ''));

    return array_map(floatval(...), $parts === false ? [] : $parts);
}

it('leaves an unrotated page exactly as it was', function () {
    // The placement is already in user space, so nothing should move and no
    // matrix should be written. Every document that is not rotated has to
    // produce the bytes it produced before.
    expect(sealedRectangle(pageRotated(null)))->toBe([60.0, 400.0, 180.0, 460.0]);
});

it('maps the placement onto the page the file describes', function (int $rotate, array $expected) {
    expect(sealedRectangle(pageRotated($rotate)))->toBe($expected);
})->with([
    // Derived rather than guessed: for a quarter turn clockwise the user-space
    // origin, the bottom left, is displayed at the top left, so a displayed
    // point (x, y) sits at user (width - y, x).
    'a quarter turn' => [90, [135.0, 60.0, 195.0, 180.0]],
    'upside down' => [180, [415.0, 382.0, 535.0, 442.0]],
    'three quarters' => [270, [400.0, 662.0, 460.0, 782.0]],
    'a full turn is no turn' => [360, [60.0, 400.0, 180.0, 460.0]],
    'expressed the other way round' => [-90, [400.0, 662.0, 460.0, 782.0]],
]);

it('swaps the sides, because the displayed page has', function () {
    // The clearest single consequence: a box 120 wide and 60 tall on screen is
    // 60 wide and 120 tall in the file.
    [$x1, $y1, $x2, $y2] = sealedRectangle(pageRotated(90));

    expect($x2 - $x1)->toBe(60.0)
        ->and($y2 - $y1)->toBe(120.0);
});

it('inherits the rotation from the page tree, which is where a landscape document declares it', function () {
    // /Rotate and /MediaBox are inheritable (ISO 32000-1 §7.7.3.4, Table 30),
    // and one declaration on /Pages is the common way to say "this document is
    // landscape". Reading the page object alone would miss it.
    $pdf = pdfWith([
        1 => '<</Type/Catalog/Pages 2 0 R>>',
        2 => '<</Type/Pages/Kids[3 0 R]/Count 1/Rotate 90/MediaBox[0 0 595 842]>>',
        3 => '<</Type/Page/Parent 2 0 R>>',
    ]);

    expect(sealedRectangle($pdf))->toBe([135.0, 60.0, 195.0, 180.0]);
});

it('turns the appearance the other way, so the seal reads upright', function () {
    [$pfxPath, $password] = debugCertificate();

    $path = A1PdfSign::tempPath(true, '.pdf');
    file_put_contents($path, pageRotated(90));

    $signed = A1PdfSign::newSignature()
        ->certificate($pfxPath, $password)
        ->pdf($path)
        ->seal(placement: new SealPlacement(x: 60, y: 400, width: 120, height: 60))
        ->sign()
        ->contents;

    // The appearance is drawn in user space, so the display rotation applies to
    // it too. Without this the seal lands correctly and reads sideways.
    expect($signed)->toContain('/Matrix[0 1 -1 0 0 0]');
});

it('writes no matrix when there is nothing to correct', function () {
    [$pfxPath, $password] = debugCertificate();

    $path = A1PdfSign::tempPath(true, '.pdf');
    file_put_contents($path, pageRotated(null));

    $signed = A1PdfSign::newSignature()
        ->certificate($pfxPath, $password)
        ->pdf($path)
        ->seal(placement: new SealPlacement(x: 60, y: 400, width: 120, height: 60))
        ->sign()
        ->contents;

    expect($signed)->not->toContain('/Matrix');
});

it('behaves as an unrotated page when the document declares no media box', function () {
    // The mapping needs the page's size, and inventing one would put the seal
    // somewhere arbitrary. Landing where it used to is at least predictable.
    expect(new PageGeometry()->isRotated())->toBeFalse()
        ->and(PageGeometry::of(90, null)->isRotated())->toBeFalse()
        ->and(PageGeometry::of(90, null)->toUserSpace(60, 400, 120, 60))
        ->toBe([60.0, 400.0, 180.0, 460.0]);
});

it('normalises a rotation expressed outside a single turn', function () {
    expect(PageGeometry::of(450, [0.0, 0.0, 595.0, 842.0])->rotation)->toBe(90)
        ->and(PageGeometry::of(-270, [0.0, 0.0, 595.0, 842.0])->rotation)->toBe(90)
        ->and(PageGeometry::of(-90, [0.0, 0.0, 595.0, 842.0])->rotation)->toBe(270);
});
