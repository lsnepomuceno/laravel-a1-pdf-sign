<?php

declare(strict_types=1);

namespace LSNepomuceno\LaravelA1PdfSign\Commands\Concerns;

/**
 * Console input, typed.
 *
 * `argument()` and `option()` return `array|bool|string|null`, which PHPStan
 * refuses to hand to a method expecting a string. Every command in the package
 * had grown its own private copy of these two, which is the point at which a
 * shared one stops being premature.
 *
 * An absent or non-scalar value becomes an empty string rather than raising:
 * the argument definition already decides what is required, and a command that
 * re-litigated it would disagree with `--help`.
 */
trait ReadsTypedInput
{
    protected function stringArgument(string $key): string
    {
        $value = $this->argument($key);

        return is_string($value) ? $value : '';
    }

    protected function stringOption(string $key): ?string
    {
        $value = $this->option($key);

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * A numeric option, or null when it was not given.
     */
    protected function floatOption(string $key): ?float
    {
        $value = $this->option($key);

        return is_numeric($value) ? (float) $value : null;
    }

    protected function intOption(string $key): ?int
    {
        $value = $this->option($key);

        return is_numeric($value) ? (int) $value : null;
    }
}
