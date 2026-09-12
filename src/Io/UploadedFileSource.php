<?php

declare(strict_types=1);

namespace LSNepomuceno\LaravelA1PdfSign\Io;

use Illuminate\Http\UploadedFile;
use LSNepomuceno\Signet\Contracts\PdfSource;
use LSNepomuceno\Signet\Exceptions\FileNotFoundException;

/**
 * A document that arrived in a request.
 *
 * The upload is read where it already is rather than moved into a temporary
 * file first: signing needs the bytes and not a path, and every extra copy of
 * a document is another place it can be left behind.
 */
final readonly class UploadedFileSource implements PdfSource
{
    public function __construct(private UploadedFile $file) {}

    /**
     * @throws FileNotFoundException When the temporary upload is already gone.
     */
    #[\Override]
    public function contents(): string
    {
        $contents = $this->file->get();

        if ($contents === false || $contents === '') {
            throw new FileNotFoundException($this->name());
        }

        return $contents;
    }

    #[\Override]
    public function name(): string
    {
        return $this->file->getClientOriginalName();
    }
}
