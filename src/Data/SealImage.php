<?php

namespace LSNepomuceno\LaravelA1PdfSign\Data;

/**
 * A rendered seal, still in memory.
 *
 * v1 wrote the seal to a temporary PNG so the signer could read it back by
 * path. Carrying the bytes plus the dimensions the PDF image XObject needs
 * removes that round-trip entirely.
 */
final readonly class SealImage extends BaseData
{
    public function __construct(
        public string $contents,
        public int $width,
        public int $height,
        public string $mimeType = 'image/jpeg',
    ) {}

    /**
     * The PDF filter that embeds these bytes without re-encoding them.
     */
    public function pdfFilter(): string
    {
        return match ($this->mimeType) {
            'image/jpeg' => 'DCTDecode',
            default => throw new \InvalidArgumentException(
                "no PDF filter embeds {$this->mimeType} without re-encoding",
            ),
        };
    }

    public function toDataUri(): string
    {
        return "data:{$this->mimeType};base64," . base64_encode($this->contents);
    }
}
