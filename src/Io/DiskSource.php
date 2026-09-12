<?php

declare(strict_types=1);

namespace LSNepomuceno\LaravelA1PdfSign\Io;

use Illuminate\Contracts\Filesystem\Filesystem;
use LSNepomuceno\Signet\Contracts\PdfSource;
use LSNepomuceno\Signet\Exceptions\FileNotFoundException;
use Throwable;

/**
 * A document on a Laravel disk.
 *
 * **This is what the wrapper buys that the standalone package cannot have.**
 * signet-pdf reads a path, a stream or a string, and all three assume the
 * bytes are already reachable from this machine. A Laravel application's
 * documents commonly are not: they are on S3, and signing one meant
 * downloading it to a temporary file, signing that, and uploading the result
 * (docs/decisions/0039-the-core-lives-in-signet-pdf.md).
 *
 * The disk is taken as a `Filesystem` rather than as a disk name, so
 * `Storage::fake()` and a disk configured at runtime both work without this
 * class knowing which.
 */
final readonly class DiskSource implements PdfSource
{
    public function __construct(
        private Filesystem $disk,
        private string $path,
    ) {}

    /**
     * @throws FileNotFoundException When the disk has no such file, or refused
     *                               to read it.
     */
    #[\Override]
    public function contents(): string
    {
        try {
            $contents = $this->disk->get($this->path);
        } catch (Throwable) {
            throw new FileNotFoundException($this->path);
        }

        // A disk answers a missing file with null rather than by raising, and
        // an empty document is not a document either: both would arrive at
        // signing as "could not parse", which sends the reader looking at the
        // PDF instead of at the path.
        if ($contents === null || $contents === '') {
            throw new FileNotFoundException($this->path);
        }

        return $contents;
    }

    #[\Override]
    public function name(): string
    {
        return pathinfo($this->path, PATHINFO_BASENAME);
    }
}
