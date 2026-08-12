<?php

declare(strict_types=1);

namespace LSNepomuceno\LaravelA1PdfSign\Enums;

/**
 * The stream filters this package decodes, ISO 32000-1 §7.4.
 *
 * Named by their PDF names so a `/Filter` entry resolves with `tryFrom()`. The
 * two image codecs, `/DCTDecode` and `/JPXDecode`, are absent on purpose: this
 * package reads streams to find objects, and an image is never one.
 */
enum StreamFilter: string
{
    case Flate = 'FlateDecode';

    case Lzw = 'LZWDecode';

    case AsciiHex = 'ASCIIHexDecode';

    case Ascii85 = 'ASCII85Decode';

    case RunLength = 'RunLengthDecode';

    /**
     * Whether a predictor can sit in front of this filter, §7.4.4.4.
     *
     * Only the two that compress binary take one. A predictor on an ASCII
     * filter is not illegal, it is meaningless, and applying one anyway would
     * corrupt a payload that decoded correctly.
     */
    public function takesPredictor(): bool
    {
        return $this === self::Flate || $this === self::Lzw;
    }
}
