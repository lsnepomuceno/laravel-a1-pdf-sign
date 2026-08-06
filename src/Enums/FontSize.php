<?php

namespace LSNepomuceno\LaravelA1PdfSign\Enums;

/**
 * Font size of the seal's text.
 *
 * The backing values match the string constants this replaces
 * (SealImage::FONT_SIZE_*), so the legacy values still resolve through from().
 */
enum FontSize: string
{
    case Small = 'FONT_SIZE_SMALL';
    case Medium = 'FONT_SIZE_MEDIUM';
    case Large = 'FONT_SIZE_LARGE';

    /**
     * Point size passed to the image driver.
     */
    public function points(): int
    {
        return match ($this) {
            self::Small => 15,
            self::Medium => 20,
            self::Large => 28,
        };
    }

    /**
     * Character count after which a line is wrapped.
     *
     * Larger type fits fewer characters, so this moves opposite to points().
     */
    public function cropLength(): int
    {
        return match ($this) {
            self::Small => 60,
            self::Medium => 48,
            self::Large => 35,
        };
    }

    /**
     * Accepts either an instance or one of the legacy string constants.
     */
    public static function resolve(self|string $value): self
    {
        return $value instanceof self ? $value : (self::tryFrom($value) ?? self::Large);
    }
}
