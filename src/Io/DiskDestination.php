<?php

declare(strict_types=1);

namespace LSNepomuceno\LaravelA1PdfSign\Io;

use Illuminate\Contracts\Filesystem\Filesystem;
use LSNepomuceno\Signet\Contracts\PdfDestination;
use LSNepomuceno\Signet\Exceptions\FileNotFoundException;

/**
 * Where a signed document lands, on a Laravel disk.
 *
 * The counterpart to `DiskSource`: read from a disk, sign, write back to one,
 * with no local file at either end.
 */
final readonly class DiskDestination implements PdfDestination
{
    /**
     * @param  string|null  $path  Where to write. Null uses the name the
     *          document already carries, which is what `SignedPdf::name()`
     *          answers: the source's file name, or a time-ordered unique one
     *          when it has none.
     */
    public function __construct(
        private Filesystem $disk,
        private ?string $path = null,
    ) {}

    /**
     * @return string The path written, so a caller can store it.
     *
     * @throws FileNotFoundException When the disk refused the write.
     */
    #[\Override]
    public function write(string $contents, string $name): string
    {
        $path = $this->path ?? $name;

        // A disk answers a refused write with false, and a signature that was
        // produced and then silently not stored is the worse of the two
        // failures: the caller believes it has a signed document.
        if ($this->disk->put($path, $contents) === false) {
            throw new FileNotFoundException($path);
        }

        return $path;
    }
}
