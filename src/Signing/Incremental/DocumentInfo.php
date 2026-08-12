<?php

namespace LSNepomuceno\LaravelA1PdfSign\Signing\Incremental;

use LSNepomuceno\LaravelA1PdfSign\Signing\Encryption\StandardSecurityHandler;

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
        /**
         * The trailer's /ID array as written, brackets included, or null.
         *
         * ISO 32000-1 §14.4 and §7.5.5: it identifies the file, and every
         * revision's trailer carries it. Dropping it in an appended revision
         * is what made a signed PDF/A document stop conforming
         * (docs/decisions/0025-what-signing-does-to-pdf-a.md).
         */
        public ?string $id = null,
        /**
         * The key material for an encrypted document, once a password has
         * opened it. Null for the ordinary case, which is most documents
         * (docs/decisions/0030-signing-a-document-that-is-encrypted.md).
         */
        public ?StandardSecurityHandler $security = null,
        /**
         * The object number of `/Encrypt`, which every trailer in the file has
         * to repeat: a revision whose trailer omits it reads as the point where
         * the document stopped being encrypted, and everything before it then
         * decodes as noise.
         */
        public int $encryptRef = 0,
    ) {}

    /**
     * The same document, carrying the key that opens it.
     */
    public function encrypted(StandardSecurityHandler $security, int $reference): self
    {
        return new self(
            $this->xref,
            $this->size,
            $this->root,
            $this->infoRef,
            $this->startxref,
            $this->usesXrefStream,
            $this->compressed,
            $this->id,
            $security,
            $reference,
        );
    }

    /**
     * Whether anything written into this document has to be encrypted first.
     */
    public function isEncrypted(): bool
    {
        return $this->security !== null;
    }

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
