<?php

namespace LSNepomuceno\LaravelA1PdfSign\Enums;

/**
 * How a seal's pixels are stored inside the PDF, ISO 32000-1 §7.4 and §8.9.5.
 *
 * PDF has no PNG filter. A transparent seal is therefore stored as raw colour
 * samples with the alpha channel beside it in an /SMask, rather than as the
 * PNG file the renderer produced.
 */
enum SealEncoding: string
{
    /** JPEG bytes embedded as they are, which is what an opaque seal costs least as. */
    case Jpeg = 'jpeg';

    /** Deflated 8-bit RGB samples, so an /SMask can carry the alpha channel. */
    case Rgb = 'rgb';

    public function pdfFilter(): string
    {
        return match ($this) {
            self::Jpeg => 'DCTDecode',
            self::Rgb => 'FlateDecode',
        };
    }

    /**
     * What `contents` actually is, which is only an image file in one case.
     */
    public function mimeType(): string
    {
        return match ($this) {
            self::Jpeg => 'image/jpeg',
            self::Rgb => 'application/octet-stream',
        };
    }

    /**
     * Whether this encoding can carry an alpha channel at all.
     *
     * JPEG cannot, which is the whole reason the other case exists.
     */
    public function carriesAlpha(): bool
    {
        return $this === self::Rgb;
    }
}
