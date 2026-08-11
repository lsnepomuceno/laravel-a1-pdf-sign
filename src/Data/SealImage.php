<?php

namespace LSNepomuceno\LaravelA1PdfSign\Data;

use InvalidArgumentException;
use LSNepomuceno\LaravelA1PdfSign\Enums\SealEncoding;

/**
 * A rendered seal, still in memory.
 *
 * v1 wrote the seal to a temporary PNG so the signer could read it back by
 * path. Carrying the bytes plus the dimensions the PDF image XObject needs
 * removes that round-trip entirely.
 */
final readonly class SealImage extends BaseData
{
    /**
     * @param  string  $contents  The bytes as they go into the stream: a JPEG
     *                            file, or deflated RGB samples. `$encoding`
     *                            says which.
     * @param  ?string  $alpha  Deflated 8-bit greyscale samples, one per pixel,
     *                          for the /SMask. Null when the seal is opaque,
     *                          which JPEG always is.
     */
    public function __construct(
        public string $contents,
        public int $width,
        public int $height,
        public string $mimeType = 'image/jpeg',
        public ?string $alpha = null,
        public SealEncoding $encoding = SealEncoding::Jpeg,
    ) {}

    /**
     * The PDF filter that embeds these bytes without re-encoding them.
     *
     * @throws InvalidArgumentException
     */
    public function pdfFilter(): string
    {
        // The encoding is authoritative. mimeType stays because it is public
        // and still true of $contents, but it describes the bytes rather than
        // deciding how they are stored.
        if ($this->encoding === SealEncoding::Jpeg && $this->mimeType !== 'image/jpeg') {
            throw new InvalidArgumentException("no PDF filter embeds {$this->mimeType} without re-encoding");
        }

        return $this->encoding->pdfFilter();
    }

    /**
     * Whether this seal carries an alpha channel to put in an /SMask.
     */
    public function isTransparent(): bool
    {
        return $this->alpha !== null && $this->encoding->carriesAlpha();
    }

    /**
     * Meaningful only for an encoding that is an image file, which is JPEG.
     * Raw samples are not something a browser can render.
     */
    public function toDataUri(): string
    {
        return "data:{$this->mimeType};base64," . base64_encode($this->contents);
    }
}
