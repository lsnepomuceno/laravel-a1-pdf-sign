<?php

namespace LSNepomuceno\LaravelA1PdfSign\Signing\Incremental;

/**
 * The parts of a PDF an incremental revision needs to chain onto.
 *
 * @internal
 */
final readonly class DocumentInfo
{
    /**
     * @param  array<int, int>  $xref  Object number to byte offset, with newer
     *                                 revisions already applied over older ones.
     * @param  array<int, int>  $compressed  Object number to the object stream
     *                                       that packs it, ISO 32000-1 §7.5.7.
     *                                       Disjoint from $xref: an object is
     *                                       at an offset or inside a stream,
     *                                       and a newer revision moving it out
     *                                       removes it from here.
     */
    public function __construct(
        public array $xref,
        public int $size,
        public int $root,
        public ?string $infoRef,
        public int $startxref,
        public bool $usesXrefStream = false,
        public array $compressed = [],
    ) {}

    /**
     * Whether this object has to be unpacked before it can be read.
     */
    public function isCompressed(int $number): bool
    {
        return isset($this->compressed[$number]);
    }

    /**
     * Whether the document locates this object at all, in either map.
     */
    public function has(int $number): bool
    {
        return isset($this->xref[$number]) || isset($this->compressed[$number]);
    }

    /**
     * Every object the document declares, however it stores them.
     *
     * @return list<int>
     */
    public function objectNumbers(): array
    {
        $numbers = array_merge(array_keys($this->xref), array_keys($this->compressed));
        sort($numbers);

        return $numbers;
    }

    /**
     * The first object number free for the revision being appended.
     */
    public function nextObjectNumber(): int
    {
        return $this->size;
    }
}
