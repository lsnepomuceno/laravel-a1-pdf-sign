<?php

namespace LSNepomuceno\LaravelA1PdfSign\Data;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * A signed document, before anyone decides what to do with it.
 *
 * v1 made the signer choose between returning bytes and returning a download
 * response, which forced a pointless write-then-read through disk for the
 * bytes case (§1.8). Here signing produces the bytes and the caller picks the
 * transport afterwards.
 */
final readonly class SignedPdf extends BaseData
{
    public function __construct(
        public string $contents,
        public string $fileName = '',
    ) {}

    public function contents(): string
    {
        return $this->contents;
    }

    public function size(): int
    {
        return strlen($this->contents);
    }

    /**
     * Writes the document and returns the path it was written to.
     */
    public function save(string $path): string
    {
        File::put($path, $this->contents);

        return $path;
    }

    public function download(?string $fileName = null): BinaryFileResponse
    {
        $path = $this->toTemporaryPath();

        return response()
            ->download($path, $fileName ?? $this->downloadName())
            ->deleteFileAfterSend();
    }

    /**
     * Renders the document inline, for previewing in a browser.
     */
    public function toResponse(?string $fileName = null): Response
    {
        return response($this->contents, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . ($fileName ?? $this->downloadName()) . '"',
        ]);
    }

    public function __toString(): string
    {
        return $this->contents;
    }

    private function downloadName(): string
    {
        return $this->fileName !== '' ? $this->fileName : Str::orderedUuid() . '.pdf';
    }

    /**
     * download() needs a file on disk; this is the only place one is created,
     * and the response deletes it after sending.
     */
    private function toTemporaryPath(): string
    {
        $path = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . Str::orderedUuid() . '.pdf';

        File::put($path, $this->contents);

        return $path;
    }
}
