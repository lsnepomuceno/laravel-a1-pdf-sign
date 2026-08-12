<?php

declare(strict_types=1);

namespace LSNepomuceno\LaravelA1PdfSign\Validation;

/**
 * Reads the length a DER structure declares about itself.
 *
 * A signature's /Contents is a fixed-width placeholder padded with zeros, so
 * the CMS has to be cut at its declared length. Trimming the padding with
 * rtrim() instead would cut legitimate 0x00 bytes off the end of the DER, a
 * bug PoC 0b hit and this exists to keep fixed.
 *
 * ISO/IEC 8825-1 §8.1.3: a first length byte below 0x80 is the length itself;
 * otherwise its low seven bits count the bytes that carry it.
 */
final class DerReader
{
    /**
     * The total size of the structure at the start of $binary, or 0 when the
     * header is malformed.
     */
    public function declaredLength(string $binary): int
    {
        if (strlen($binary) < 2) {
            return 0;
        }

        $lengthByte = ord($binary[1]);

        if ($lengthByte < 0x80) {
            return 2 + $lengthByte;
        }

        $count = $lengthByte & 0x7F;

        // 0x80 alone is the indefinite form, which DER forbids; anything longer
        // than the buffer is truncated.
        if ($count === 0 || strlen($binary) < 2 + $count) {
            return 0;
        }

        $length = 0;

        for ($index = 0; $index < $count; $index++) {
            $length = ($length << 8) | ord($binary[2 + $index]);
        }

        return 2 + $count + $length;
    }

    /**
     * $binary cut to its declared length, or an empty string when the header is
     * malformed or the structure runs past the buffer.
     */
    public function truncate(string $binary): string
    {
        $length = $this->declaredLength($binary);

        if ($length === 0 || $length > strlen($binary)) {
            return '';
        }

        return substr($binary, 0, $length);
    }
}
