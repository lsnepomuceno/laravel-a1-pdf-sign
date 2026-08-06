<?php

namespace LSNepomuceno\LaravelA1PdfSign\Enums;

use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\Drivers\Imagick\Driver as ImagickDriver;
use Intervention\Image\Interfaces\DriverInterface;

/**
 * Image backend used to render the seal.
 *
 * The backing values match the string constants this replaces
 * (SealImage::IMAGE_DRIVER_*).
 */
enum ImageDriver: string
{
    case Gd = 'gd';
    case Imagick = 'imagick';

    public function create(): DriverInterface
    {
        return match ($this) {
            self::Gd => new GdDriver(),
            self::Imagick => new ImagickDriver(),
        };
    }

    /**
     * The enum case backing a driver instance, or null when it is a third-party
     * driver the package does not support.
     */
    public static function fromDriver(DriverInterface $driver): ?self
    {
        return match ($driver::class) {
            GdDriver::class => self::Gd,
            ImagickDriver::class => self::Imagick,
            default => null,
        };
    }
}
