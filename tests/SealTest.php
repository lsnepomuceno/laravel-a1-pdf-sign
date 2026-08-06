<?php

use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\ImageManager;
use LSNepomuceno\LaravelA1PdfSign\Contracts\SealRenderer;
use LSNepomuceno\LaravelA1PdfSign\Data\SealImage;
use LSNepomuceno\LaravelA1PdfSign\Data\SealPlacement;
use LSNepomuceno\LaravelA1PdfSign\Enums\FontSize;
use LSNepomuceno\LaravelA1PdfSign\Facades\A1PdfSign;
use LSNepomuceno\LaravelA1PdfSign\Signing\Incremental\SealAppearance;

it('renders a seal carrying the certificate identity', function () {
    $seal = app(SealRenderer::class)->render(testCertificate());

    expect($seal)->toBeInstanceOf(SealImage::class)
        ->and($seal->width)->toBe(590)
        ->and($seal->height)->toBe(295)
        ->and($seal->mimeType)->toBe('image/jpeg')
        ->and($seal->pdfFilter())->toBe('DCTDecode');

    // The bytes must be a real image, not just non-empty.
    $decoded = (new ImageManager(driver: new GdDriver()))->read($seal->contents);

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
    $seal = app(SealRenderer::class)->render(testCertificate());
    $object = (new SealAppearance())->imageObject(20, $seal);

    expect($object)->toContain('/Subtype/Image')
        ->toContain('/Filter/DCTDecode')
        ->toContain("/Width {$seal->width}/Height {$seal->height}")
        ->toContain('/Length ' . strlen($seal->contents))
        // The stream is the original JPEG, byte for byte.
        ->toContain($seal->contents);
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

    [, $y1, , $y2] = (new SealAppearance())->rectangle(
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

    expect($signed->contents)->toContain('/Subtype/Image')
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

    expect($signed->contents)->toContain('/Rect[0 0 0 0]')
        ->not->toContain('/AP<</N ');
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
