<?php

namespace LSNepomuceno\LaravelA1PdfSign\Support;

/**
 * The decoded payload of a stream object.
 *
 * Two structures need it and both are compressed by default: the
 * cross-reference stream of ISO 32000-1 §7.5.8 and the object stream of §7.5.7.
 * Producers that emit one almost always emit the other, since packing objects
 * into a stream is what the cross-reference stream exists to index.
 */
final readonly class PdfStream
{
    public function __construct(
        private PdfDictionary $dictionaries = new PdfDictionary(),
    ) {}

    /**
     * The dictionary of the object that starts at $offset.
     */
    public function dictionaryAt(string $contents, int $offset): ?string
    {
        $open = strpos($contents, '<<', $offset);

        return $open === false ? null : $this->dictionaries->at($contents, $open);
    }

    /**
     * The stream's bytes, decoded through whichever filter it declares.
     *
     * Null when the object carries no stream, when its filter is one this
     * package does not decode, or when the payload does not decode. The caller
     * says what that means: an unreadable cross-reference section is fatal, an
     * unreadable object stream leaves objects unresolvable.
     */
    public function contentsAt(string $contents, int $offset, string $dictionary): ?string
    {
        // Searched from the end of the dictionary rather than from the object,
        // so a dictionary that happens to contain the word never stands in for
        // the keyword.
        $after = strpos($contents, $dictionary, $offset);

        if ($after === false) {
            return null;
        }

        $keyword = strpos($contents, 'stream', $after + strlen($dictionary));

        if ($keyword === false) {
            return null;
        }

        // "stream" is followed by CRLF or LF, and by nothing else.
        $start = $keyword + 6;
        $start += str_starts_with(substr($contents, $start, 2), "\r\n") ? 2 : 1;

        $length = $this->declaredLength($dictionary);

        if ($length < 1) {
            $end = strpos($contents, 'endstream', $start);
            $length = ($end === false ? strlen($contents) : $end) - $start;

            // ISO 32000-1 §7.3.8.1: the EOL before "endstream" belongs to the
            // syntax, not to the data. Keeping it corrupts an unfiltered
            // payload by a byte, which the filtered ones tolerate and so hide.
            $length -= $this->trailingEol($contents, $start + $length);
        }

        return $this->decode(substr($contents, $start, $length), $dictionary);
    }

    /**
     * How many bytes of end-of-line sit just before $position, at most one
     * marker: a payload may legitimately end in a newline of its own.
     */
    private function trailingEol(string $contents, int $position): int
    {
        if (substr($contents, $position - 2, 2) === "\r\n") {
            return 2;
        }

        return in_array(substr($contents, $position - 1, 1), ["\n", "\r"], true) ? 1 : 0;
    }

    private function decode(string $raw, string $dictionary): ?string
    {
        if (! str_contains($dictionary, '/Filter')) {
            return $raw;
        }

        if (preg_match('/\/Filter\s*\/(FlateDecode|ASCIIHexDecode)/', $dictionary, $filter) !== 1) {
            return null;
        }

        $decoded = $filter[1] === 'FlateDecode'
            ? @gzuncompress($raw)
            : @hex2bin(trim($raw, "> \n\r\t"));

        return $decoded === false ? null : $decoded;
    }

    private function declaredLength(string $dictionary): int
    {
        // An indirect /Length is legal and is not resolved here: falling back to
        // the endstream keyword covers it and covers a wrong length too.
        return preg_match('/\/Length\s+(\d+)(?!\s+\d+\s+R)/', $dictionary, $found) === 1 ? (int) $found[1] : 0;
    }
}
