<?php

namespace LSNepomuceno\LaravelA1PdfSign\Support;

use LSNepomuceno\LaravelA1PdfSign\Enums\StreamFilter;

/**
 * Decodes a stream payload through the filters it declares, ISO 32000-1 §7.4.
 *
 * Previously two filters were handled, `/FlateDecode` and `/ASCIIHexDecode`,
 * each as a bare name, and `/DecodeParms` was ignored entirely. That covers
 * what this package writes and a fair share of what it is handed, and it fails
 * on the rest in the least useful way: an object that cannot be read is an
 * object the signer refuses to sign around, so a document nothing is wrong with
 * comes back as unsignable.
 *
 * Three things were missing and all three are ordinary:
 *
 *   - **a filter chain**, `/Filter [/ASCII85Decode /FlateDecode]`, applied in
 *     order. Distiller emits it, and so does anything that ASCII-armours a
 *     compressed stream;
 *   - **a predictor**, `/DecodeParms <</Predictor 12 /Columns 5>>`. Every
 *     cross-reference stream a modern generator writes uses PNG-Up, because the
 *     rows of a cross-reference table differ from each other by very little;
 *   - **`/LZWDecode`**, which predates Flate and still turns up in documents
 *     produced by older tooling.
 *
 * Nothing here decodes an image: `/DCTDecode` and `/JPXDecode` are deliberately
 * absent, since streams are read to find objects and an image is never one.
 *
 * See docs/decisions/0020-decode-the-filters-documents-use.md.
 *
 * @internal
 */
final readonly class PdfFilters
{
    public function __construct(
        private PdfDictionary $dictionaries = new PdfDictionary(),
    ) {}

    /**
     * The payload with every declared filter undone, or null when one of them
     * is a filter this does not implement or the bytes do not decode.
     *
     * Null rather than the raw bytes: handing back something undecoded that
     * looks like a dictionary is how a caller ends up parsing compressed noise.
     */
    public function decode(string $raw, string $dictionary): ?string
    {
        $filters = $this->filters($dictionary);

        if ($filters === []) {
            return str_contains($dictionary, '/Filter') ? null : $raw;
        }

        $parameters = $this->parameters($dictionary, count($filters));
        $decoded = $raw;

        foreach ($filters as $index => $filter) {
            $decoded = $this->apply($filter, $decoded, $parameters[$index] ?? '');

            if ($decoded === null) {
                return null;
            }
        }

        return $decoded;
    }

    /**
     * The declared filters in application order.
     *
     * A single name and an array of names are both legal and mean the same
     * thing for one filter, §7.3.8.2. An unknown name yields an empty list, so
     * the caller sees "not decodable" rather than a partial decode.
     *
     * @return list<StreamFilter>
     */
    private function filters(string $dictionary): array
    {
        if (preg_match('/\/Filter\s*\[([^\]]*)\]/', $dictionary, $array) === 1) {
            $names = preg_match_all('/\/([A-Za-z0-9]+)/', $array[1], $found) > 0 ? $found[1] : [];
        } elseif (preg_match('/\/Filter\s*\/([A-Za-z0-9]+)/', $dictionary, $single) === 1) {
            $names = [$single[1]];
        } else {
            return [];
        }

        $filters = [];

        foreach ($names as $name) {
            $filter = StreamFilter::tryFrom($name);

            if ($filter === null) {
                return [];
            }

            $filters[] = $filter;
        }

        return $filters;
    }

    /**
     * The /DecodeParms dictionary for each filter, as raw dictionary text.
     *
     * One dictionary for one filter, an array of them for a chain, and `null`
     * in that array for a filter that takes none, §7.3.8.2.
     *
     * @return list<string>
     */
    private function parameters(string $dictionary, int $count): array
    {
        // /DP is the abbreviation, legal in an inline image and emitted by some
        // producers elsewhere.
        if (preg_match('/\/(?:DecodeParms|DP)\s*(?=[\[<])/', $dictionary, $found, PREG_OFFSET_CAPTURE) !== 1) {
            return [];
        }

        $start = $found[0][1] + strlen($found[0][0]);

        if ($dictionary[$start] === '<') {
            return [(string) $this->dictionaries->at($dictionary, $start)];
        }

        // An array: walk it taking each balanced dictionary in turn, so a
        // nested one cannot end the entry early.
        $parameters = [];
        $position = $start + 1;

        while (count($parameters) < $count && $position < strlen($dictionary)) {
            $next = strpos($dictionary, '<<', $position);
            $close = strpos($dictionary, ']', $position);

            if ($next === false || ($close !== false && $close < $next)) {
                break;
            }

            $entry = $this->dictionaries->at($dictionary, $next);

            if ($entry === null) {
                break;
            }

            // A filter taking no parameters is written as null, and the array
            // stays positional, so the gaps have to be kept.
            $nulls = preg_match_all('/\bnull\b/', substr($dictionary, $position, $next - $position));
            $parameters = array_pad($parameters, count($parameters) + (int) $nulls, '');
            $parameters[] = $entry;
            $position = $next + strlen($entry);
        }

        return $parameters;
    }

    private function apply(StreamFilter $filter, string $data, string $parameters): ?string
    {
        $decoded = match ($filter) {
            StreamFilter::Flate => $this->inflate($data),
            StreamFilter::Lzw => $this->lzw($data, $this->parameter($parameters, 'EarlyChange', 1) === 1),
            StreamFilter::AsciiHex => $this->asciiHex($data),
            StreamFilter::Ascii85 => $this->ascii85($data),
            StreamFilter::RunLength => $this->runLength($data),
        };

        if ($decoded === null || ! $filter->takesPredictor()) {
            return $decoded;
        }

        return $this->unpredict($decoded, $parameters);
    }

    /**
     * zlib, then raw deflate.
     *
     * The specification says zlib, §7.4.4.2, and a producer emitting the raw
     * stream without the two-byte header is common enough that refusing it
     * would reject documents every reader opens.
     */
    private function inflate(string $data): ?string
    {
        $decoded = @gzuncompress($data);

        if ($decoded === false) {
            $decoded = @gzinflate($data);
        }

        return $decoded === false ? null : $decoded;
    }

    private function asciiHex(string $data): ?string
    {
        // '>' is the end-of-data marker, and whitespace is legal anywhere.
        $body = strstr($data, '>', true);
        $hex = (string) preg_replace('/\s+/', '', $body === false ? $data : $body);

        if (preg_match('/^[0-9A-Fa-f]*$/', $hex) !== 1) {
            return null;
        }

        // An odd final digit is padded with zero, §7.4.2.
        $decoded = @hex2bin(strlen($hex) % 2 === 1 ? $hex . '0' : $hex);

        return $decoded === false ? null : $decoded;
    }

    /**
     * ASCII85, §7.4.3.
     */
    private function ascii85(string $data): ?string
    {
        $body = (string) preg_replace('/\s+/', '', $data);

        // The leading <~ is not part of the PDF form but is accepted, and ~> ends it.
        $body = str_starts_with($body, '<~') ? substr($body, 2) : $body;
        $end = strpos($body, '~>');
        $body = $end === false ? $body : substr($body, 0, $end);

        $out = '';
        $group = [];

        for ($index = 0; $index < strlen($body); $index++) {
            $char = $body[$index];

            // 'z' stands for a whole group of zero bytes, and only between groups.
            if ($char === 'z' && $group === []) {
                $out .= "\0\0\0\0";

                continue;
            }

            $value = ord($char) - 33;

            if ($value < 0 || $value > 84) {
                return null;
            }

            $group[] = $value;

            if (count($group) === 5) {
                $out .= $this->ascii85Group($group, 4);
                $group = [];
            }
        }

        if ($group === []) {
            return $out;
        }

        // A partial final group is padded with 'u', and yields one byte fewer
        // than it has digits.
        $bytes = count($group) - 1;
        $group = array_pad($group, 5, 84);

        return $out . $this->ascii85Group($group, $bytes);
    }

    /**
     * @param  list<int>  $group
     */
    private function ascii85Group(array $group, int $bytes): string
    {
        $value = 0;

        foreach ($group as $digit) {
            $value = $value * 85 + $digit;
        }

        return substr(pack('N', $value), 0, $bytes);
    }

    /**
     * Run-length, §7.4.5: a length byte, then either a literal run or a
     * repeated byte, ending at 128.
     */
    private function runLength(string $data): ?string
    {
        $out = '';
        $position = 0;
        $length = strlen($data);

        while ($position < $length) {
            $marker = ord($data[$position++]);

            if ($marker === 128) {
                break;
            }

            if ($marker < 128) {
                $take = $marker + 1;

                if ($position + $take > $length) {
                    return null;
                }

                $out .= substr($data, $position, $take);
                $position += $take;

                continue;
            }

            if ($position >= $length) {
                return null;
            }

            $out .= str_repeat($data[$position++], 257 - $marker);
        }

        return $out;
    }

    /**
     * LZW, §7.4.4.2, with the variable code width PDF and TIFF share.
     *
     * `/EarlyChange` decides whether the width grows one code before the
     * dictionary is actually full. It defaults to 1, and a decoder that ignores
     * it produces plausible bytes that are wrong from the first width change on.
     */
    private function lzw(string $data, bool $earlyChange): ?string
    {
        $dictionary = [];
        $out = '';
        $previous = null;
        $width = 9;
        $next = 258;
        $buffer = 0;
        $bits = 0;

        for ($index = 0; $index <= strlen($data); $index++) {
            if ($index < strlen($data)) {
                $buffer = ($buffer << 8) | ord($data[$index]);
                $bits += 8;
            } elseif ($bits < $width) {
                break;
            }

            while ($bits >= $width) {
                $code = ($buffer >> ($bits - $width)) & ((1 << $width) - 1);
                $bits -= $width;

                // 256 resets everything, 257 ends the data.
                if ($code === 256) {
                    $dictionary = [];
                    $width = 9;
                    $next = 258;
                    $previous = null;

                    continue;
                }

                if ($code === 257) {
                    return $out;
                }

                if ($code < 256) {
                    $entry = chr($code);
                } elseif (isset($dictionary[$code])) {
                    $entry = $dictionary[$code];
                } elseif ($previous !== null) {
                    // The code the encoder created on this very step, which the
                    // decoder has not built yet.
                    $entry = $previous . $previous[0];
                } else {
                    return null;
                }

                $out .= $entry;

                if ($previous !== null) {
                    $dictionary[$next++] = $previous . $entry[0];
                }

                $previous = $entry;

                $limit = $earlyChange ? 1 : 0;

                if ($next + $limit >= (1 << $width) && $width < 12) {
                    $width++;
                }
            }
        }

        return $out;
    }

    /**
     * Undoes the predictor a filter's parameters declare, §7.4.4.4.
     */
    private function unpredict(string $data, string $parameters): ?string
    {
        $predictor = $this->parameter($parameters, 'Predictor', 1);

        if ($predictor <= 1) {
            return $data;
        }

        $colors = $this->parameter($parameters, 'Colors', 1);
        $bits = $this->parameter($parameters, 'BitsPerComponent', 8);
        $columns = $this->parameter($parameters, 'Columns', 1);

        if ($colors < 1 || $bits < 1 || $columns < 1) {
            return null;
        }

        // Bytes per pixel, at least one: a sub-byte sample still shifts by a
        // whole byte for the purposes of the PNG filters.
        $pixel = max(1, intdiv($colors * $bits, 8));
        $row = intdiv($columns * $colors * $bits + 7, 8);

        return $predictor === 2
            ? $this->undoTiff($data, $row, $pixel, $bits)
            : $this->undoPng($data, $row, $pixel);
    }

    /**
     * TIFF predictor 2: horizontal differencing.
     *
     * Only the 8-bit case is undone. Sub-byte components would need the samples
     * unpacked and repacked, and a producer that pairs them with a predictor is
     * rare enough that guessing is worse than saying no.
     */
    private function undoTiff(string $data, int $row, int $pixel, int $bits): ?string
    {
        if ($bits !== 8) {
            return null;
        }

        $out = '';

        foreach (str_split($data, max(1, $row)) as $line) {
            $bytes = $this->bytes($line);

            for ($index = $pixel; $index < count($bytes); $index++) {
                $bytes[$index] = ($bytes[$index] + $bytes[$index - $pixel]) & 0xFF;
            }

            $out .= pack('C*', ...$bytes);
        }

        return $out;
    }

    /**
     * PNG predictors 10 to 15, RFC 2083 §6.
     *
     * Each row is preceded by the filter type that was applied to it, so the
     * type is per row rather than per stream: /Predictor 15 means "any of
     * them", which is why the declared value is not used beyond deciding that
     * a PNG predictor is in play at all.
     */
    private function undoPng(string $data, int $row, int $pixel): ?string
    {
        $out = '';
        $previous = array_fill(0, $row, 0);
        $position = 0;
        $length = strlen($data);

        while ($position + 1 <= $length) {
            $type = ord($data[$position++]);
            $line = substr($data, $position, $row);

            if ($line === '') {
                break;
            }

            $position += strlen($line);

            $bytes = $this->bytes($line);
            $count = count($bytes);

            for ($index = 0; $index < $count; $index++) {
                $left = $index >= $pixel ? $bytes[$index - $pixel] : 0;
                $up = $previous[$index] ?? 0;
                $upLeft = $index >= $pixel ? ($previous[$index - $pixel] ?? 0) : 0;

                $bytes[$index] = match ($type) {
                    0 => $bytes[$index],
                    1 => ($bytes[$index] + $left) & 0xFF,
                    2 => ($bytes[$index] + $up) & 0xFF,
                    3 => ($bytes[$index] + intdiv($left + $up, 2)) & 0xFF,
                    4 => ($bytes[$index] + $this->paeth($left, $up, $upLeft)) & 0xFF,
                    default => -1,
                };

                if ($bytes[$index] === -1) {
                    return null;
                }
            }

            $out .= pack('C*', ...$bytes);
            $previous = $bytes;
        }

        return $out;
    }

    private function paeth(int $left, int $up, int $upLeft): int
    {
        $estimate = $left + $up - $upLeft;
        $distanceLeft = abs($estimate - $left);
        $distanceUp = abs($estimate - $up);
        $distanceUpLeft = abs($estimate - $upLeft);

        if ($distanceLeft <= $distanceUp && $distanceLeft <= $distanceUpLeft) {
            return $left;
        }

        return $distanceUp <= $distanceUpLeft ? $up : $upLeft;
    }

    /**
     * @return list<int>
     */
    private function bytes(string $line): array
    {
        $unpacked = unpack('C*', $line);

        if ($unpacked === false) {
            return [];
        }

        /** @var list<int> $values */
        $values = array_values($unpacked);

        return $values;
    }

    private function parameter(string $parameters, string $key, int $default): int
    {
        return preg_match('/\/' . $key . '\s+(\d+)/', $parameters, $found) === 1 ? (int) $found[1] : $default;
    }
}
