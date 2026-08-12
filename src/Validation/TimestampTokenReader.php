<?php

namespace LSNepomuceno\LaravelA1PdfSign\Validation;

use LSNepomuceno\LaravelA1PdfSign\Enums\Asn1Tag;
use LSNepomuceno\LaravelA1PdfSign\Enums\CmsAttribute;

/**
 * Finds the RFC 3161 token a B-T signature carries, and what it stamps.
 *
 * The package has embedded this token since 2.0 and never read it back. A
 * document at `pades-b-t` therefore reported valid without anyone having looked
 * at the one thing the profile adds over B-B: a third party's word on when the
 * signature existed. Only the DocTimeStamp of B-LTA was ever verified, which is
 * the same asymmetry
 * ([0010](../../docs/decisions/0010-validation-consumes-what-signing-writes.md))
 * one level down.
 *
 * **The token does not stamp the document.** It stamps the SignerInfo's
 * signature value, RFC 3161 §2.4.1 by way of CAdES: the imprint is the digest of
 * those octets and of nothing else. Checking it against the document's bytes
 * would fail on every correctly built file.
 *
 * See docs/decisions/0019-validation-reads-what-it-writes.md.
 *
 * @internal
 */
final readonly class TimestampTokenReader
{
    public function __construct(
        private Asn1Reader $asn1 = new Asn1Reader(),
    ) {}

    /**
     * The token and the bytes it stamps, or null when the CMS carries none.
     *
     * @return array{token: string, stamped: string}|null
     */
    public function read(string $cms): ?array
    {
        $signerInfo = $this->signerInfo($cms);

        if ($signerInfo === null) {
            return null;
        }

        $children = $this->asn1->childrenOf($cms, $signerInfo);
        $unsigned = null;
        $unsignedIndex = null;

        foreach ($children as $index => $child) {
            if ($child->is(Asn1Tag::Context1)) {
                $unsigned = $child;
                $unsignedIndex = $index;
            }
        }

        if ($unsigned === null || $unsignedIndex === null || $unsignedIndex < 1) {
            return null;
        }

        // signature sits immediately before unsignedAttrs, SignerInfo being a
        // fixed sequence with two optional members at known positions.
        $signature = $children[$unsignedIndex - 1];

        if (! $signature->is(Asn1Tag::OctetString)) {
            return null;
        }

        $token = $this->token($cms, $unsigned);

        return $token === null ? null : ['token' => $token, 'stamped' => $signature->content($cms)];
    }

    /**
     * The genTime a verified TSTInfo asserts, as a unix timestamp.
     *
     * TSTInfo ::= SEQUENCE { version, policy, messageImprint, serialNumber,
     * genTime, ... }, RFC 3161 §2.4.2, so genTime is the fifth field. Read by
     * position rather than by looking for the first GeneralizedTime, because
     * the accuracy and the extensions after it can carry times of their own.
     */
    public function stampedAt(string $tstInfo): ?int
    {
        $root = $this->asn1->at($tstInfo);

        if ($root === null) {
            return null;
        }

        $genTime = $this->asn1->path($tstInfo, $root, [4]);

        return $genTime === null ? null : $this->asn1->generalizedTime($tstInfo, $genTime);
    }

    /**
     * The single SignerInfo of a detached CMS.
     *
     * ContentInfo → [0] content → SignedData → signerInfos, the last field of
     * the SignedData sequence (RFC 5652 §5.1). A CMS with more than one signer
     * is not something this package produces, and the first is the one whose
     * signature the rest of the report describes.
     */
    private function signerInfo(string $cms): ?Asn1Node
    {
        $root = $this->asn1->at($cms);

        if ($root === null || ! $root->is(Asn1Tag::Sequence)) {
            return null;
        }

        $signedData = $this->asn1->path($cms, $root, [1, 0]);

        if ($signedData === null || ! $signedData->is(Asn1Tag::Sequence)) {
            return null;
        }

        $fields = $this->asn1->childrenOf($cms, $signedData);
        $signerInfos = $fields === [] ? null : $fields[count($fields) - 1];

        if ($signerInfos === null || ! $signerInfos->is(Asn1Tag::Set)) {
            return null;
        }

        $signers = $this->asn1->childrenOf($cms, $signerInfos);

        return $signers[0] ?? null;
    }

    /**
     * The TimeStampToken inside the unsigned attributes, as DER.
     */
    private function token(string $cms, Asn1Node $unsigned): ?string
    {
        foreach ($this->asn1->childrenOf($cms, $unsigned) as $attribute) {
            $parts = $this->asn1->childrenOf($cms, $attribute);

            if (count($parts) !== 2 || ! $parts[1]->is(Asn1Tag::Set)) {
                continue;
            }

            if ($this->asn1->oid($cms, $parts[0]) !== CmsAttribute::SignatureTimestamp->value) {
                continue;
            }

            $values = $this->asn1->childrenOf($cms, $parts[1]);

            if ($values === []) {
                return null;
            }

            return $values[0]->raw($cms);
        }

        return null;
    }
}
