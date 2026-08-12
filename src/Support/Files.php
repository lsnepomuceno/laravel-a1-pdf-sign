<?php

declare(strict_types=1);

namespace LSNepomuceno\LaravelA1PdfSign\Support;

use LSNepomuceno\LaravelA1PdfSign\Exceptions\FileNotFoundException;

/**
 * Reads a file, or says why it could not.
 *
 * file_get_contents() and File::get() return false on failure, and passing
 * that straight into a string parameter is the single most common typing
 * defect this package had. Failing here names the file instead.
 */
final class Files
{
    /**
     * @throws FileNotFoundException
     */
    public static function read(string $path): string
    {
        $contents = @file_get_contents($path);

        if ($contents === false) {
            throw new FileNotFoundException($path);
        }

        return $contents;
    }
}
