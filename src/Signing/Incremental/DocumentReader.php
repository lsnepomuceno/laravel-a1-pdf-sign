<?php

namespace LSNepomuceno\LaravelA1PdfSign\Signing\Incremental;

use LSNepomuceno\LaravelA1PdfSign\Exceptions\InvalidPdfFileException;

/**
 * Reads the cross-reference chain of an existing PDF.
 *
 * Clean-room implementation from ISO 32000-1 §7.5.4, §7.5.6 and §7.5.8.
 *
 * Both cross-reference forms are read: the classic table of §7.5.4 and the
 * cross-reference stream of §7.5.8, which PDF 1.5 introduced and most modern
 * generators emit.
 *
 * @internal
 */
final class DocumentReader
{
    public function __construct(private readonly XrefStreamReader $streams = new XrefStreamReader()) {}

    /**
     * Walks the /Prev chain and returns the effective view of the document.
     *
     * @throws InvalidPdfFileException
     */
    public function read(string $pdf): DocumentInfo
    {
        if (preg_match_all('/startxref\s+(\d+)\s*%%EOF/', $pdf, $matches) === 0) {
            throw new InvalidPdfFileException('no startxref pointer found; the file is not a PDF or is truncated');
        }

        $latest = (int) end($matches[1]);

        $chain = [];
        $offset = $latest;
        $seen = [];

        while ($offset > 0 && ! isset($seen[$offset])) {
            $seen[$offset] = true;
            $section = $this->readSection($pdf, $offset);
            $chain[] = $section;
            $offset = $section['prev'];
        }

        $xref = [];
        $size = 0;
        $root = 0;
        $infoRef = null;
        $usesStream = false;

        // The chain runs newest to oldest, so walk it reversed and let the most
        // recent entries win.
        foreach (array_reverse($chain) as $section) {
            $xref = array_replace($xref, $section['xref']);
            $size = max($size, $section['size']);
            $usesStream = $usesStream || $section['stream'];

            if ($section['root'] > 0) {
                $root = $section['root'];
            }

            if ($section['infoRef'] !== null) {
                $infoRef = $section['infoRef'];
            }
        }

        if ($root === 0) {
            throw new InvalidPdfFileException('no /Root entry found in any trailer');
        }

        return new DocumentInfo($xref, $size, $root, $infoRef, $latest, $usesStream);
    }

    /**
     * The raw dictionary of an object, without its "N 0 obj" and "endobj".
     *
     * @throws InvalidPdfFileException
     */
    public function rawObject(string $pdf, DocumentInfo $document, int $number): string
    {
        $offset = $document->xref[$number] ?? null;

        if ($offset === null) {
            throw new InvalidPdfFileException("object {$number} is missing from the cross-reference table");
        }

        $end = strpos($pdf, 'endobj', $offset);

        if ($end === false) {
            throw new InvalidPdfFileException("object {$number} has no endobj marker");
        }

        $raw = substr($pdf, $offset, $end - $offset);

        return rtrim((string) preg_replace('/^\s*\d+\s+\d+\s+obj\s*/', '', $raw, 1));
    }

    /**
     * @throws InvalidPdfFileException
     */
    public function findFirstPage(string $pdf, DocumentInfo $document): int
    {
        foreach ($document->xref as $number => $offset) {
            if ($offset <= 0) {
                continue;
            }

            // Bounded at endobj, not at a byte count. A fixed window reaches
            // into whatever follows, and in a compact document that is the next
            // object: the catalog was reported as the first page because a
            // /Type/Page two objects later fell inside its window, and the
            // revision then wrote the signature's /AcroForm and its /Annots
            // both onto object 1, the second overwriting the first.
            $end = strpos($pdf, 'endobj', $offset);
            $object = substr($pdf, $offset, ($end === false ? strlen($pdf) : $end) - $offset);

            // The negative lookahead keeps /Pages, the page tree root, out.
            if (preg_match('/\/Type\s*\/Page(?![s\w])/', $object) === 1) {
                return $number;
            }
        }

        throw new InvalidPdfFileException('no page object found');
    }

    /**
     * @return array{xref: array<int, int>, size: int, root: int, infoRef: ?string, prev: int, stream: bool}
     *
     * @throws InvalidPdfFileException
     */
    private function readSection(string $pdf, int $offset): array
    {
        if (! str_starts_with(ltrim(substr($pdf, $offset, 32)), 'xref')) {
            // PDF 1.5 packs the same table into a stream object. Reading only
            // the classic form bounded the package to documents older tools
            // produce (docs/decisions/0009-cross-reference-streams.md).
            if ($this->streams->handles($pdf, $offset)) {
                return $this->streams->read($pdf, $offset);
            }

            throw new InvalidPdfFileException(
                "the cross-reference section at offset {$offset} is neither a table nor a stream",
            );
        }

        $position = strpos($pdf, 'xref', $offset);

        if ($position === false) {
            throw new InvalidPdfFileException("unreadable cross-reference section at offset {$offset}");
        }

        $position += 4;
        $xref = [];

        // Subsections: "<first> <count>" followed by <count> entries of exactly
        // 20 bytes each.
        while (preg_match('/\G\s*(\d+)\s+(\d+)\s*(?:\r\n|\r|\n)/', $pdf, $header, 0, $position) === 1) {
            $first = (int) $header[1];
            $count = (int) $header[2];
            $position += strlen($header[0]);

            for ($i = 0; $i < $count; $i++) {
                $entry = substr($pdf, $position + ($i * 20), 20);

                if (preg_match('/^(\d{10})\s(\d{5})\s([nf])/', $entry, $parts) === 1 && $parts[3] === 'n') {
                    $xref[$first + $i] = (int) $parts[1];
                }
            }

            $position += $count * 20;
        }

        $trailerPosition = strpos($pdf, 'trailer', $position);

        if ($trailerPosition === false) {
            throw new InvalidPdfFileException('no trailer after the cross-reference table');
        }

        $trailer = substr($pdf, $trailerPosition, 2048);

        // Encryption is refused rather than mis-handled. The cross-reference
        // table is not encrypted, so reading gets far enough to look successful
        // while the strings and streams around it are unreadable, and the
        // revision appended beside them would not match the rest of the file.
        // See docs/decisions/0014-refuse-encrypted-documents.md.
        if (preg_match('/\/Encrypt\s/', $trailer) === 1) {
            throw new InvalidPdfFileException(
                'the document is encrypted; signing it would append a revision the rest of the file cannot decrypt',
            );
        }

        preg_match('/\/Size\s+(\d+)/', $trailer, $size);
        preg_match('/\/Root\s+(\d+)\s+\d+\s+R/', $trailer, $root);
        preg_match('/\/Prev\s+(\d+)/', $trailer, $prev);
        preg_match('/\/Info\s+(\d+\s+\d+\s+R)/', $trailer, $info);

        return [
            'xref' => $xref,
            'size' => isset($size[1]) ? (int) $size[1] : 0,
            'root' => isset($root[1]) ? (int) $root[1] : 0,
            'infoRef' => $info[1] ?? null,
            'prev' => isset($prev[1]) ? (int) $prev[1] : 0,
            'stream' => false,
        ];
    }
}
