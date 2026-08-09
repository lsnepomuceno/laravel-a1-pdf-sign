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
     */
    public function __construct(
        public array $xref,
        public int $size,
        public int $root,
        public ?string $infoRef,
        public int $startxref,
        public bool $usesXrefStream = false,
    ) {}

    /**
     * The first object number free for the revision being appended.
     */
    public function nextObjectNumber(): int
    {
        return $this->size;
    }
}
