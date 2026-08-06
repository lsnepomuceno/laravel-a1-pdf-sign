<?php

use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\Drivers\Imagick\Driver as ImagickDriver;
use LSNepomuceno\LaravelA1PdfSign\Enums\FontSize;
use LSNepomuceno\LaravelA1PdfSign\Enums\ImageDriver;

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

it('resolves a font size from its backing value, as configuration supplies it', function () {
    expect(FontSize::resolve('small'))->toBe(FontSize::Small)
        ->and(FontSize::resolve('medium'))->toBe(FontSize::Medium)
        ->and(FontSize::resolve('large'))->toBe(FontSize::Large)
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
