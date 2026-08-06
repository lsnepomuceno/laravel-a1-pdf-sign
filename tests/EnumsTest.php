<?php

use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\Drivers\Imagick\Driver as ImagickDriver;
use LSNepomuceno\LaravelA1PdfSign\Enums\FontSize;
use LSNepomuceno\LaravelA1PdfSign\Enums\ImageDriver;
use LSNepomuceno\LaravelA1PdfSign\Enums\SignatureMode;
use LSNepomuceno\LaravelA1PdfSign\Sign\SealImage;
use LSNepomuceno\LaravelA1PdfSign\Sign\SignaturePdf;

it('carries the point size for each font size', function (FontSize $size, int $points) {
    expect($size->points())->toBe($points);
})->with([
    [FontSize::Small, 15],
    [FontSize::Medium, 20],
    [FontSize::Large, 28],
]);

it('wraps shorter lines as the type gets larger', function () {
    expect(FontSize::Small->cropLength())->toBe(60)
        ->and(FontSize::Medium->cropLength())->toBe(48)
        ->and(FontSize::Large->cropLength())->toBe(35);
});

it('resolves the legacy font size constants', function () {
    expect(FontSize::resolve(SealImage::FONT_SIZE_SMALL))->toBe(FontSize::Small)
        ->and(FontSize::resolve(SealImage::FONT_SIZE_MEDIUM))->toBe(FontSize::Medium)
        ->and(FontSize::resolve(SealImage::FONT_SIZE_LARGE))->toBe(FontSize::Large)
        ->and(FontSize::resolve(FontSize::Medium))->toBe(FontSize::Medium);
});

it('falls back to the large font size for an unknown value', function () {
    expect(FontSize::resolve('nonsense'))->toBe(FontSize::Large);
});

it('builds the image driver instances', function () {
    expect(ImageDriver::Gd->create())->toBeInstanceOf(GdDriver::class)
        ->and(ImageDriver::Imagick->create())->toBeInstanceOf(ImagickDriver::class);
});

it('maps a driver instance back to its case', function () {
    expect(ImageDriver::fromDriver(new GdDriver()))->toBe(ImageDriver::Gd)
        ->and(ImageDriver::fromDriver(new ImagickDriver()))->toBe(ImageDriver::Imagick);
});

it('matches the legacy image driver constants', function () {
    expect(ImageDriver::Gd->value)->toBe(SealImage::IMAGE_DRIVER_GD)
        ->and(ImageDriver::Imagick->value)->toBe(SealImage::IMAGE_DRIVER_IMAGICK);
});

it('resolves the legacy signature mode constants', function () {
    expect(SignatureMode::resolve(SignaturePdf::MODE_RESOURCE))->toBe(SignatureMode::Resource)
        ->and(SignatureMode::resolve(SignaturePdf::MODE_DOWNLOAD))->toBe(SignatureMode::Download)
        ->and(SignatureMode::resolve('nonsense'))->toBeNull();
});
