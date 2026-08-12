<?php

declare(strict_types=1);

namespace LSNepomuceno\LaravelA1PdfSign\Enums;

/**
 * Font size of the seal's text.
 */
enum FontSize: string
{
    case Small = 'small';
    case Medium = 'medium';
    case Large = 'large';

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
     * Accepts an instance or its backing value, so configuration can express
     * the size as a plain string.
     */
    public static function resolve(self|string $value): self
    {
        return $value instanceof self ? $value : (self::tryFrom($value) ?? self::Large);
    }
}
