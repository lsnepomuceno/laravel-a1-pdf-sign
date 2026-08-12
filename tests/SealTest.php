<?php

declare(strict_types=1);

use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\ImageManager;
use LSNepomuceno\LaravelA1PdfSign\Contracts\SealRenderer;
use LSNepomuceno\LaravelA1PdfSign\Data\SealImage;
use LSNepomuceno\LaravelA1PdfSign\Data\SealPlacement;
use LSNepomuceno\LaravelA1PdfSign\Enums\FontSize;
use LSNepomuceno\LaravelA1PdfSign\Enums\SealEncoding;
use LSNepomuceno\LaravelA1PdfSign\Facades\A1PdfSign;
use LSNepomuceno\LaravelA1PdfSign\Signing\Incremental\SealAppearance;

it('renders a seal carrying the certificate identity', function () {
    // Transparent by default: the artwork has an alpha channel and flattening
    // it was the defect (docs/decisions/0023-a-seal-that-can-be-transparent.md).
    $seal = app(SealRenderer::class)->render(testCertificate());

    expect($seal)->toBeInstanceOf(SealImage::class)
        ->and($seal->width)->toBe(590)
        ->and($seal->height)->toBe(295)
        ->and($seal->encoding)->toBe(SealEncoding::Rgb)
        ->and($seal->pdfFilter())->toBe('FlateDecode')
        ->and($seal->isTransparent())->toBeTrue();

    // The samples must really be the image: three bytes a pixel, one for alpha.
    $rgb = (string) gzuncompress($seal->contents);
    $alpha = (string) gzuncompress((string) $seal->alpha);

    expect(strlen($rgb))->toBe(590 * 295 * 3)
        ->and(strlen($alpha))->toBe(590 * 295)
        // The artwork really is partly transparent, which is the whole point:
        // an all-opaque mask would be an expensive way to draw a rectangle.
        ->and(count(array_unique(str_split($alpha))))->toBeGreaterThan(1)
        ->and(str_contains($alpha, "\x00"))->toBeTrue()
        ->and(str_contains($alpha, "\xff"))->toBeTrue();
});

it('renders an opaque JPEG when transparency is turned off', function () {
    config()->set('a1-pdf-sign.seal.transparent', false);

    $seal = app(SealRenderer::class)->render(testCertificate());

    expect($seal->mimeType)->toBe('image/jpeg')
        ->and($seal->pdfFilter())->toBe('DCTDecode')
        ->and($seal->isTransparent())->toBeFalse()
        ->and($seal->alpha)->toBeNull();

    // The bytes must be a real image, not just non-empty.
    $decoded = new ImageManager(driver: new GdDriver())->read($seal->contents);

    expect($decoded->width())->toBe(590);
});

it('honours the configured font colour and size', function () {
    config()->set('a1-pdf-sign.seal.font.size', 'small');

    $small = app(SealRenderer::class)->render(testCertificate());
    $large = app(SealRenderer::class)->render(testCertificate(), FontSize::Large);

    // Same canvas, different type: the encoded payloads must differ.
    expect($small->contents)->not->toBe($large->contents);
});

it('embeds the seal as a JPEG passthrough, without re-encoding', function () {
    config()->set('a1-pdf-sign.seal.transparent', false);

    $seal = app(SealRenderer::class)->render(testCertificate());
    $object = new SealAppearance()->imageObject(20, $seal);

    expect($object)->toContain('/Subtype/Image')
        ->toContain('/Filter/DCTDecode')
        ->toContain("/Width {$seal->width}/Height {$seal->height}")
        ->toContain('/Length ' . strlen($seal->contents))
        // The stream is the original JPEG, byte for byte.
        ->toContain($seal->contents)
        // Nothing to mask, so no /SMask even when a number is offered.
        ->and(new SealAppearance()->imageObject(20, $seal, 21))->not->toContain('/SMask');
});

it('points a transparent seal at its soft mask', function () {
    // §8.9.5.4: the alpha channel is a separate greyscale image, not a fourth
    // component of the colour one.
    $seal = app(SealRenderer::class)->render(testCertificate());
    $appearance = new SealAppearance();

    expect($appearance->imageObject(20, $seal, 21))
        ->toContain('/ColorSpace/DeviceRGB')
        ->toContain('/SMask 21 0 R')
        ->and($appearance->maskObject(21, $seal))
        ->toContain('/ColorSpace/DeviceGray')
        ->toContain('/BitsPerComponent 8')
        ->toContain('/Filter/FlateDecode')
        ->toContain('/Length ' . strlen((string) $seal->alpha))
        // A mask carrying its own mask would be a loop.
        ->not->toContain('/SMask');
});

it('keeps the aspect ratio when no height is given', function () {
    $seal = new SealImage('x', 600, 300);
    $appearance = new SealAppearance();

    [$x1, $y1, $x2, $y2] = $appearance->rectangle(new SealPlacement(width: 50, x: 10, y: 20), $seal);

    expect($x1)->toBe(10.0)
        ->and($y1)->toBe(20.0)
        ->and($x2)->toBe(60.0)
        ->and($y2 - $y1)->toBe(25.0);
});

it('uses an explicit height when one is given', function () {
    $seal = new SealImage('x', 600, 300);

    [, $y1, , $y2] = new SealAppearance()->rectangle(
        new SealPlacement(width: 50, height: 80, y: 0),
        $seal,
    );

    expect($y2 - $y1)->toBe(80.0);
});

it('signs with a visible seal', function () {
    [$pfxPath, $password] = debugCertificate();

    $signed = A1PdfSign::newSignature()
        ->certificate($pfxPath, $password)
        ->pdf(resource('test.pdf'))
        ->seal()
        ->sign();

    expect((string) $signed->contents)->toContain('/Subtype/Image')
        ->toContain('/Subtype/Form')
        ->toContain('/AP<</N ')
        // A visible signature must not keep the zero rectangle.
        ->not->toContain('/Rect[0 0 0 0]');
});

it('leaves the signature invisible when no seal is requested', function () {
    [$pfxPath, $password] = debugCertificate();

    $signed = A1PdfSign::newSignature()
        ->certificate($pfxPath, $password)
        ->pdf(resource('test.pdf'))
        ->sign();

    // A zero rectangle is what makes it invisible. It used to be asserted that
    // there was no /AP either, and there is one now: ISO 19005-1 §6.9 wants
    // every form field to have an appearance dictionary, and veraPDF failed a
    // signed PDF/A-1 document without one. The appearance draws nothing, which
    // is the same thing (docs/decisions/0025-what-signing-does-to-pdf-a.md).
    expect((string) $signed->contents)->toContain('/Rect[0 0 0 0]')
        ->toContain('/BBox[0 0 0 0]')
        // Nothing to draw with: no image, no form beyond the empty one.
        ->not->toContain('/Subtype/Image');
});

it('still validates a document signed with a visible seal', function () {
    [$pfxPath, $password] = debugCertificate();

    $signed = A1PdfSign::newSignature()
        ->certificate($pfxPath, $password)
        ->pdf(resource('test.pdf'))
        ->seal()
        ->sign();

    $path = $signed->save(A1PdfSign::tempPath(true, '.pdf'));

    expect(A1PdfSign::validate($path)->isValid())->toBeTrue();

    unlink($path);
});

it('gives each signature its own seal, independent of the ones before it', function () {
    // Nothing shares state between signatures by construction: newSignature()
    // is bound with bind() rather than singleton(), and SealAppearance emits an
    // image and a form XObject per revision. This asserts the construction,
    // since neither the seal tests nor the multi-signature tests cover both at
    // once, and a regression would be silent until someone opened the file.
    [$pfxPath, $password] = debugCertificate();

    $first = A1PdfSign::newSignature()
        ->certificate($pfxPath, $password)
        ->pdf(resource('test.pdf'))
        ->info(name: 'First signer')
        ->seal(placement: new SealPlacement(x: 150, y: 240, width: 50))
        ->sign();

    $path = $first->save(A1PdfSign::tempPath(true, '.pdf'));

    $second = A1PdfSign::newSignature()
        ->certificate($pfxPath, $password)
        ->pdf($path)
        ->info(name: 'Second signer')
        ->sealFrom(__DIR__ . '/../src/Resources/img/sign-seal.png', new SealPlacement(x: 30, y: 60, width: 60))
        ->sign();

    $contents = (string) $second->contents;

    // One image and one form per signature, not one pair reused by both.
    // Two per seal: the colour samples and the soft mask carrying the alpha.
    expect(substr_count($contents, '/Subtype/Image'))->toBe(4)
        ->and(substr_count($contents, '/Subtype/Form'))->toBe(2);

    // Two distinct rectangles, neither of them the invisible one.
    preg_match_all('/\/Rect\[([^\]]+)\]/', $contents, $rectangles);

    expect($rectangles[1])->toHaveCount(2)
        ->and($rectangles[1][0])->not->toBe($rectangles[1][1])
        ->and($rectangles[1])->not->toContain('0 0 0 0');

    // The second seal is the caller's own image rather than a re-render of the
    // certificate. sealFrom() stored the path on the placement and nothing read
    // it, so both seals used to be the same certificate render
    // (docs/decisions/0023-a-seal-that-can-be-transparent.md).
    expect($contents)->toContain('/FlateDecode');

    preg_match_all('/\/Subtype\/Image.*?stream\n(.*?)\nendstream/s', $contents, $streams);

    expect($streams[1])->toHaveCount(4)
        // The two colour planes differ; identical ones would mean the supplied
        // image was ignored again.
        ->and($streams[1][0])->not->toBe($streams[1][2]);

    $signedPath = $second->save(A1PdfSign::tempPath(true, '.pdf'));
    $report = A1PdfSign::validate($signedPath);

    expect($report->count())->toBe(2)
        ->and($report->isValid())->toBeTrue();

    unlink($path);
    unlink($signedPath);
});

it('draws the lines the layout names, instead of the certificate', function () {
    // The renderer drew three lines it chose out of the certificate, at three
    // baselines fixed in the source. A seal that has to carry a protocol number
    // or a second language had nowhere to put it
    // (docs/decisions/0023-a-seal-that-can-be-transparent.md).
    $default = app(SealRenderer::class)->render(testCertificate());

    $custom = app(SealRenderer::class)->render(
        testCertificate(),
        layout: LSNepomuceno\LaravelA1PdfSign\Data\SealLayout::saying([
            'Approved by the board',
            'Protocol 4471/2026',
        ]),
    );

    expect($custom->contents)->not->toBe($default->contents)
        ->and($custom->width)->toBe($default->width);
});

it('does not draw a line the layout gives no baseline for', function () {
    // Stacking it onto the last row would put two lines of text on top of each
    // other, which reads as a rendering fault rather than a caller mistake.
    $twoRows = app(SealRenderer::class)->render(
        testCertificate(),
        layout: new LSNepomuceno\LaravelA1PdfSign\Data\SealLayout(
            lines: ['first', 'second', 'third'],
            rows: [80, 150],
        ),
    );

    $twoLines = app(SealRenderer::class)->render(
        testCertificate(),
        layout: new LSNepomuceno\LaravelA1PdfSign\Data\SealLayout(
            lines: ['first', 'second'],
            rows: [80, 150],
        ),
    );

    expect($twoRows->contents)->toBe($twoLines->contents);
});

it('moves the text where the layout puts it', function () {
    $left = app(SealRenderer::class)->render(
        testCertificate(),
        layout: new LSNepomuceno\LaravelA1PdfSign\Data\SealLayout(lines: ['x'], rows: [80], x: 20),
    );

    $right = app(SealRenderer::class)->render(
        testCertificate(),
        layout: new LSNepomuceno\LaravelA1PdfSign\Data\SealLayout(lines: ['x'], rows: [80], x: 400),
    );

    expect($left->contents)->not->toBe($right->contents);
});

it('takes the caller own image as the seal, which sealFrom promised all along', function () {
    // sealFrom() wrote the path onto the placement and nothing ever read it, so
    // the caller's artwork was silently replaced by a render of the certificate.
    $supplied = app(SealRenderer::class)->fromImage(__DIR__ . '/../src/Resources/img/sign-seal.png');
    $rendered = app(SealRenderer::class)->render(testCertificate());

    expect($supplied->width)->toBe(590)
        ->and($supplied->height)->toBe(295)
        // No text drawn over it: a caller who supplied artwork did not ask for
        // the certificate's details on top.
        ->and($supplied->contents)->not->toBe($rendered->contents);
});

it('raises when the seal image is not there', function () {
    expect(fn() => app(SealRenderer::class)->fromImage('/no/such/seal.png'))
        ->toThrow(LSNepomuceno\LaravelA1PdfSign\Exceptions\FileNotFoundException::class);
});

it('can still write text over a supplied image', function () {
    $plain = app(SealRenderer::class)->fromImage(__DIR__ . '/../src/Resources/img/sign-seal.png');

    $annotated = app(SealRenderer::class)->fromImage(
        __DIR__ . '/../src/Resources/img/sign-seal.png',
        LSNepomuceno\LaravelA1PdfSign\Data\SealLayout::saying(['Countersigned']),
    );

    expect($annotated->contents)->not->toBe($plain->contents);
});
