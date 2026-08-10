<?php

namespace LSNepomuceno\LaravelA1PdfSign\Signing\Incremental;

use LSNepomuceno\LaravelA1PdfSign\Exceptions\InvalidPdfFileException;
use LSNepomuceno\LaravelA1PdfSign\Support\PdfStream;

/**
 * Reads a cross-reference stream, ISO 32000-1 §7.5.8.
 *
 * PDF 1.5 replaced the classic table with a stream object whose data is a
 * packed table of fixed-width fields, so the same information arrives
 * compressed and as an ordinary object. Word, "print to PDF" in Chrome, LaTeX
 * with compression and most modern generators emit this form, which is why
 * reading only classic tables bounded who could use the package.
 *
 * See docs/decisions/0009-cross-reference-streams.md.
 *
 * @internal
 */
final readonly class XrefStreamReader
{
    public function __construct(
        private PdfStream $streams = new PdfStream(),
    ) {}

    /**
     * Whether the section at this offset is a stream rather than a table.
     */
    public function handles(string $pdf, int $offset): bool
    {
        return preg_match('/^\s*\d+\s+\d+\s+obj\b/', substr($pdf, $offset, 64)) === 1;
    }

    /**
     * @return array{xref: array<int, int>, compressed: array<int, int>, size: int, root: int, infoRef: ?string, prev: int, stream: bool}
     *
     * @throws InvalidPdfFileException
     */
    public function read(string $pdf, int $offset): array
    {
        $dictionary = $this->dictionary($pdf, $offset);

        if (! str_contains($dictionary, '/XRef')) {
            throw new InvalidPdfFileException(
                "the object at offset {$offset} is not a cross-reference stream",
            );
        }

        $widths = $this->widths($dictionary, $offset);
        $size = $this->integer($dictionary, 'Size');

        $entries = $this->entries(
            $this->data($pdf, $offset, $dictionary),
            $widths,
            $this->index($dictionary, $size),
        );

        return [
            'xref' => $entries['xref'],
            'compressed' => $entries['compressed'],
            'size' => $size,
            'root' => $this->reference($dictionary, 'Root'),
            'infoRef' => $this->rawReference($dictionary, 'Info'),
            'prev' => $this->integer($dictionary, 'Prev'),
            'stream' => true,
        ];
    }

    /**
     * @throws InvalidPdfFileException
     */
    private function dictionary(string $pdf, int $offset): string
    {
        $dictionary = $this->streams->dictionaryAt($pdf, $offset);

        if ($dictionary === null) {
            throw new InvalidPdfFileException("no stream dictionary at offset {$offset}");
        }

        return $dictionary;
    }

    /**
     * @throws InvalidPdfFileException
     */
    private function data(string $pdf, int $offset, string $dictionary): string
    {
        $data = $this->streams->contentsAt($pdf, $offset, $dictionary);

        if ($data === null) {
            throw new InvalidPdfFileException(
                "the cross-reference stream at offset {$offset} could not be read: " . $dictionary,
            );
        }

        return $data;
    }

    /**
     * The field widths from /W, which say how many bytes each of the three
     * columns takes. A width of zero means the field is absent and takes its
     * default, which for the type column is 1.
     *
     * @return array{0: int, 1: int, 2: int}
     *
     * @throws InvalidPdfFileException
     */
    private function widths(string $dictionary, int $offset): array
    {
        if (preg_match('/\/W\s*\[\s*(\d+)\s+(\d+)\s+(\d+)/', $dictionary, $found) !== 1) {
            throw new InvalidPdfFileException("the cross-reference stream at offset {$offset} declares no /W");
        }

        return [(int) $found[1], (int) $found[2], (int) $found[3]];
    }

    /**
     * The object ranges the stream describes, defaulting to all of them.
     *
     * @return list<array{0: int, 1: int}>
     */
    private function index(string $dictionary, int $size): array
    {
        if (preg_match('/\/Index\s*\[([^\]]*)\]/', $dictionary, $found) !== 1) {
            return [[0, $size]];
        }

        preg_match_all('/\d+/', $found[1], $numbers);

        $ranges = [];

        for ($i = 0; $i + 1 < count($numbers[0]); $i += 2) {
            $ranges[] = [(int) $numbers[0][$i], (int) $numbers[0][$i + 1]];
        }

        return $ranges;
    }

    /**
     * @param  array{0: int, 1: int, 2: int}  $widths
     * @param  list<array{0: int, 1: int}>  $index
     * @return array{xref: array<int, int>, compressed: array<int, int>}
     */
    private function entries(string $data, array $widths, array $index): array
    {
        $row = array_sum($widths);
        $xref = [];
        $compressed = [];
        $position = 0;

        foreach ($index as [$first, $count]) {
            for ($i = 0; $i < $count; $i++) {
                if ($position + $row > strlen($data)) {
                    return ['xref' => $xref, 'compressed' => $compressed];
                }

                $type = $widths[0] === 0 ? 1 : $this->field($data, $position, $widths[0]);
                $second = $this->field($data, $position + $widths[0], $widths[1]);
                $position += $row;

                // Type 1 is an ordinary object and the field is its byte
                // offset. Type 2 is packed into an object stream and the field
                // is that stream's object number, which is how the catalog of a
                // Word or Chrome document is reached
                // (docs/decisions/0015-object-streams.md). Type 0 is free and
                // has nothing to point at.
                if ($type === 1) {
                    $xref[$first + $i] = $second;
                }

                if ($type === 2) {
                    $compressed[$first + $i] = $second;
                }
            }
        }

        return ['xref' => $xref, 'compressed' => $compressed];
    }

    /**
     * One big-endian field of $width bytes.
     */
    private function field(string $data, int $position, int $width): int
    {
        $value = 0;

        for ($i = 0; $i < $width; $i++) {
            $value = ($value << 8) | ord($data[$position + $i] ?? "\x00");
        }

        return $value;
    }

    private function integer(string $dictionary, string $key): int
    {
        return preg_match('/\/' . $key . '\s+(\d+)/', $dictionary, $found) === 1 ? (int) $found[1] : 0;
    }

    private function reference(string $dictionary, string $key): int
    {
        return preg_match('/\/' . $key . '\s+(\d+)\s+\d+\s+R/', $dictionary, $found) === 1 ? (int) $found[1] : 0;
    }

    private function rawReference(string $dictionary, string $key): ?string
    {
        return preg_match('/\/' . $key . '\s+(\d+\s+\d+\s+R)/', $dictionary, $found) === 1 ? $found[1] : null;
    }
}
