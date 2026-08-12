<?php

declare(strict_types=1);

namespace LSNepomuceno\LaravelA1PdfSign\Signing\Incremental;

use LSNepomuceno\LaravelA1PdfSign\Support\PdfStream;

/**
 * Reads an object that lives inside an object stream, ISO 32000-1 §7.5.7.
 *
 * PDF 1.5 lets a producer pack ordinary objects into one compressed stream and
 * index them with a cross-reference stream. The two travel together: packing is
 * what the stream form exists to make indexable, so a document with §7.5.8
 * cross-reference streams almost always has §7.5.7 object streams too.
 *
 * **This is what "signs a Word document" turned on.** Reading the
 * cross-reference stream located the objects; it did not make the catalog
 * readable, because the catalog is a dictionary and a dictionary is exactly
 * what a producer packs. Signing rewrites the catalog to register the field, so
 * a catalog it cannot read is a document it cannot sign.
 *
 * The revision appended afterwards writes those objects back at the top level,
 * uncompressed, which supersedes the packed copy. Nothing has to be unpacked in
 * place, and the original bytes still survive.
 *
 * See docs/decisions/0015-object-streams.md.
 *
 * @internal
 */
final readonly class ObjectStreamReader
{
    public function __construct(
        private PdfStream $streams = new PdfStream(),
    ) {}

    /**
     * The body of object $number, packed inside the object stream at $offset.
     *
     * Null when the stream cannot be decoded or does not carry that object,
     * which the caller reports as the object being unreachable rather than
     * guessing at a body.
     */
    public function object(string $pdf, int $offset, int $number): ?string
    {
        $dictionary = $this->streams->dictionaryAt($pdf, $offset);

        if ($dictionary === null || ! str_contains($dictionary, '/ObjStm')) {
            return null;
        }

        $data = $this->streams->contentsAt($pdf, $offset, $dictionary);

        if ($data === null) {
            return null;
        }

        // /First is where the bodies begin; everything before it is the pair
        // table, "number offset number offset ...", offsets being relative to
        // /First rather than to the stream.
        if (preg_match('/\/First\s+(\d+)/', $dictionary, $first) !== 1) {
            return null;
        }

        $bodies = (int) $first[1];
        $pairs = $this->pairs(substr($data, 0, $bodies));

        if (! isset($pairs[$number])) {
            return null;
        }

        $start = $bodies + $pairs[$number];

        // Bounded by the next body rather than by a delimiter: a packed object
        // has no "endobj", so the only thing that says where it stops is where
        // the following one starts.
        $next = $this->nextOffset($pairs, $pairs[$number]);
        $end = $next === null ? strlen($data) : $bodies + $next;

        return trim(substr($data, $start, $end - $start));
    }

    /**
     * @return array<int, int> Object number to offset from /First.
     */
    private function pairs(string $header): array
    {
        preg_match_all('/(\d+)\s+(\d+)/', $header, $found, PREG_SET_ORDER);

        $pairs = [];

        foreach ($found as $pair) {
            $pairs[(int) $pair[1]] = (int) $pair[2];
        }

        return $pairs;
    }

    /**
     * The smallest offset greater than this one.
     *
     * The pair table is not required to be ordered by offset, so "the next
     * entry" and "the next body" are not the same thing.
     *
     * @param  array<int, int>  $pairs
     */
    private function nextOffset(array $pairs, int $offset): ?int
    {
        $later = array_filter($pairs, static fn(int $one): bool => $one > $offset);

        return $later === [] ? null : min($later);
    }
}
