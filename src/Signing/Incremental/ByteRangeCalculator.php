<?php

namespace LSNepomuceno\LaravelA1PdfSign\Signing\Incremental;

use LSNepomuceno\LaravelA1PdfSign\Exceptions\InvalidPdfFileException;

/**
 * Computes and writes the /ByteRange of the revision being signed.
 *
 * The signed span cannot be known until the whole file exists, but writing it
 * would move every offset after it. The way out, per ISO 32000-1 §12.8.1, is a
 * fixed-width placeholder patched in place afterwards.
 *
 * @internal
 */
final class ByteRangeCalculator
{
    /** Fixed-width field, so a computed value can replace it byte for byte. */
    public const string FIELD = '**********';

    public static function placeholder(): string
    {
        return '/ByteRange[0 ' . self::FIELD . ' ' . self::FIELD . ' ' . self::FIELD . ']';
    }

    /**
     * Replaces the placeholder of the last revision with the real offsets.
     *
     * @param  int  $contentsHexLength  Size of the /Contents placeholder, in hex characters.
     *
     * @throws InvalidPdfFileException
     */
    public function apply(string $pdf, int $contentsHexLength): string
    {
        $contentsPosition = strrpos($pdf, '/Contents <');

        if ($contentsPosition === false) {
            throw new InvalidPdfFileException('no /Contents placeholder to sign');
        }

        $open = $contentsPosition + strlen('/Contents ');       // offset of '<'
        $close = $open + 1 + $contentsHexLength + 1;            // offset just past '>'

        $replacement = sprintf(
            '/ByteRange[0 %s %s %s]',
            str_pad((string) $open, strlen(self::FIELD)),
            str_pad((string) $close, strlen(self::FIELD)),
            str_pad((string) (strlen($pdf) - $close), strlen(self::FIELD)),
        );

        $placeholder = self::placeholder();

        if (strlen($replacement) !== strlen($placeholder)) {
            throw new InvalidPdfFileException('the computed /ByteRange does not fit its placeholder');
        }

        $position = strrpos($pdf, $placeholder);

        if ($position === false) {
            throw new InvalidPdfFileException('no /ByteRange placeholder to fill');
        }

        return substr_replace($pdf, $replacement, $position, strlen($placeholder));
    }

    /**
     * Reads back the /ByteRange of the revision just written.
     *
     * An already-signed document holds several; ours is always the last. Taking
     * the first would overwrite a previous signature's /Contents — the bug that
     * PoC 0b surfaced, which is why this is a named method with its own test.
     *
     * @return array{0: int, 1: int, 2: int} Offset of '<', offset past '>', trailing length.
     *
     * @throws InvalidPdfFileException
     */
    public function readLast(string $pdf): array
    {
        if (! preg_match_all('/\/ByteRange\[0 (\d+)\s+(\d+)\s+(\d+)\s*\]/', $pdf, $all, PREG_SET_ORDER)) {
            throw new InvalidPdfFileException('no /ByteRange could be read back');
        }

        $last = end($all);

        return [(int) $last[1], (int) $last[2], (int) $last[3]];
    }

    /**
     * The bytes a signature covers: everything except its own /Contents.
     */
    public function signableSpan(string $pdf, int $open, int $close, int $trailing): string
    {
        return substr($pdf, 0, $open) . substr($pdf, $close, $trailing);
    }
}
