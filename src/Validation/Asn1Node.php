<?php

declare(strict_types=1);

namespace LSNepomuceno\LaravelA1PdfSign\Validation;

use LSNepomuceno\LaravelA1PdfSign\Enums\Asn1Tag;

/**
 * One tag-length-value in a DER structure.
 *
 * Positions rather than bytes: a CMS is copied enough times already, and every
 * question asked of it is answerable from an offset into the original.
 *
 * The tag is kept as the raw byte and compared through `Enums\Asn1Tag`. A tag
 * outside the set the package reads is not an error, it is a node nothing asks
 * about, so storing a case here would mean failing to represent a document that
 * is perfectly valid.
 *
 * @internal
 */
final readonly class Asn1Node
{
    public function __construct(
        public int $tag,
        /** Offset of the tag byte itself. */
        public int $offset,
        /** Bytes taken by the tag and the length that follows it. */
        public int $headerLength,
        /** Bytes of content, excluding the header. */
        public int $length,
    ) {}

    public function contentOffset(): int
    {
        return $this->offset + $this->headerLength;
    }

    /** One past the last byte of this node, header included. */
    public function end(): int
    {
        return $this->contentOffset() + $this->length;
    }

    public function content(string $der): string
    {
        return substr($der, $this->contentOffset(), $this->length);
    }

    /** The node as it appears in the input, header included. */
    public function raw(string $der): string
    {
        return substr($der, $this->offset, $this->headerLength + $this->length);
    }

    /**
     * Bit 6 of the tag, ISO/IEC 8825-1 §8.1.2.5. A primitive node has no
     * children, whatever its content happens to look like.
     */
    public function isConstructed(): bool
    {
        return ($this->tag & 0x20) === 0x20;
    }

    public function is(Asn1Tag $tag): bool
    {
        return $this->tag === $tag->value;
    }
}
