<?php

declare(strict_types=1);

namespace LSNepomuceno\LaravelA1PdfSign\Validation;

use LSNepomuceno\LaravelA1PdfSign\Enums\Asn1Tag;

/**
 * Walks a DER structure by its own declared lengths.
 *
 * `DerReader` answers how long the structure at an offset is, which is all the
 * placeholder-trimming it exists for needs. Descending into a CMS to reach the
 * signature value and the unsigned attributes needs one more thing: which child
 * is which, in order. Searching for a field by its bytes instead would find the
 * same pattern anywhere it occurred, including inside a certificate the CMS
 * happens to embed.
 *
 * Everything here follows ISO/IEC 8825-1. Only what a CMS actually uses is
 * implemented: definite lengths, single-byte tags. Both restrictions are DER's
 * own (§8.1.3.2 forbids the indefinite form outright), and anything outside them
 * reads as null rather than as a guess.
 *
 * See docs/decisions/0019-validation-reads-what-it-writes.md.
 *
 * @internal
 */
final readonly class Asn1Reader
{
    /**
     * The node beginning at $offset, or null when the header is unreadable or
     * the content runs past the buffer.
     */
    public function at(string $der, int $offset = 0): ?Asn1Node
    {
        if ($offset < 0 || $offset + 2 > strlen($der)) {
            return null;
        }

        $tag = ord($der[$offset]);

        // Low tag numbers only. 0x1F in the low five bits introduces a
        // multi-byte tag, which nothing in CMS or RFC 3161 uses.
        if (($tag & 0x1F) === 0x1F) {
            return null;
        }

        $first = ord($der[$offset + 1]);

        if ($first < 0x80) {
            return $this->bounded($der, $tag, $offset, 2, $first);
        }

        $count = $first & 0x7F;

        // 0x80 alone is the indefinite form, which DER forbids. More length
        // bytes than PHP_INT can hold is a structure this will never see.
        if ($count === 0 || $count > 4 || $offset + 2 + $count > strlen($der)) {
            return null;
        }

        $length = 0;

        for ($index = 0; $index < $count; $index++) {
            $length = ($length << 8) | ord($der[$offset + 2 + $index]);
        }

        return $this->bounded($der, $tag, $offset, 2 + $count, $length);
    }

    /**
     * The children of the node at $offset, in order.
     *
     * Empty for a primitive node, and empty when any child is unreadable: a
     * partially walked structure invites a caller to index into it and get an
     * answer about the wrong field.
     *
     * @return list<Asn1Node>
     */
    public function children(string $der, int $offset = 0): array
    {
        $parent = $this->at($der, $offset);

        return $parent === null ? [] : $this->childrenOf($der, $parent);
    }

    /**
     * @return list<Asn1Node>
     */
    public function childrenOf(string $der, Asn1Node $parent): array
    {
        if (! $parent->isConstructed()) {
            return [];
        }

        $children = [];
        $position = $parent->contentOffset();
        $end = $parent->end();

        while ($position < $end) {
            $child = $this->at($der, $position);

            if ($child === null || $child->end() > $end) {
                return [];
            }

            $children[] = $child;
            $position = $child->end();
        }

        return $children;
    }

    /**
     * The first child of $parent whose tag matches.
     */
    public function child(string $der, Asn1Node $parent, Asn1Tag $tag): ?Asn1Node
    {
        foreach ($this->childrenOf($der, $parent) as $child) {
            if ($child->is($tag)) {
                return $child;
            }
        }

        return null;
    }

    /**
     * Descends by child index, one level per entry in $path.
     *
     * `$reader->path($der, $root, [1, 0])` is "the second child, then its
     * first", which is how the CMS grammar reads once the field order is known.
     *
     * @param  list<int>  $path
     */
    public function path(string $der, Asn1Node $from, array $path): ?Asn1Node
    {
        $node = $from;

        foreach ($path as $index) {
            $children = $this->childrenOf($der, $node);

            if (! isset($children[$index])) {
                return null;
            }

            $node = $children[$index];
        }

        return $node;
    }

    /**
     * The dotted form of an OBJECT IDENTIFIER's content, ISO/IEC 8825-1 §8.19.
     *
     * Compared as text rather than by matching encoded bytes: the encoding of
     * an OID can appear anywhere in a blob, and a comparison that only holds
     * because the reader arrived at the right node is a comparison worth making
     * expensive.
     */
    public function oid(string $der, ?Asn1Node $node): ?string
    {
        if ($node === null || ! $node->is(Asn1Tag::ObjectIdentifier) || $node->length < 1) {
            return null;
        }

        $bytes = $node->content($der);
        $first = ord($bytes[0]);

        // The first two arcs share one byte: 40 * first + second.
        $arcs = [intdiv($first, 40), $first % 40];
        $value = 0;
        $started = false;

        for ($index = 1; $index < strlen($bytes); $index++) {
            $byte = ord($bytes[$index]);
            $value = ($value << 7) | ($byte & 0x7F);
            $started = true;

            if (($byte & 0x80) === 0) {
                $arcs[] = $value;
                $value = 0;
                $started = false;
            }
        }

        // A final byte with the continuation bit still set is a truncated arc.
        return $started ? null : implode('.', $arcs);
    }

    /**
     * A GeneralizedTime as a unix timestamp, ISO/IEC 8824-1 §46.
     */
    public function generalizedTime(string $der, ?Asn1Node $node): ?int
    {
        if ($node === null || ! $node->is(Asn1Tag::GeneralizedTime)) {
            return null;
        }

        // YYYYMMDDHHMMSS, then optional fractional seconds, then the zone. Only
        // the Z form occurs in a timestamp token, which RFC 3161 §2.4.2 requires.
        if (preg_match('/^(\d{14})(?:\.\d+)?Z$/', $node->content($der), $found) !== 1) {
            return null;
        }

        $parsed = \DateTimeImmutable::createFromFormat('YmdHis', $found[1], new \DateTimeZone('UTC'));

        return $parsed === false ? null : $parsed->getTimestamp();
    }

    /**
     * An INTEGER's content as an uppercase hex string, which is how a
     * certificate serial number is written and compared.
     */
    public function integerAsHex(string $der, ?Asn1Node $node): ?string
    {
        if ($node === null || ! $node->is(Asn1Tag::Integer) || $node->length < 1) {
            return null;
        }

        // DER pads a positive integer whose top bit is set with a leading zero.
        // A serial is compared against openssl_x509_parse()'s serialNumberHex,
        // which does not carry that byte.
        $hex = strtoupper(ltrim(bin2hex($node->content($der)), '0'));

        // A serial of zero trims to nothing, and "" would compare equal to
        // nothing rather than to zero.
        return $hex === '' ? '0' : $hex;
    }

    private function bounded(string $der, int $tag, int $offset, int $headerLength, int $length): ?Asn1Node
    {
        if ($length < 0 || $offset + $headerLength + $length > strlen($der)) {
            return null;
        }

        return new Asn1Node($tag, $offset, $headerLength, $length);
    }
}
