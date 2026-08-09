<?php

namespace LSNepomuceno\LaravelA1PdfSign\Data;

/**
 * One signature found in a document.
 */
final readonly class SignatureDetails extends BaseData
{
    /**
     * @param  bool  $verified  Whether the embedded CMS verifies against the
     *                          bytes it covers. This is a cryptographic check,
     *                          not a statement about whether the issuer is
     *                          trusted.
     * @param  int  $coverageEnd  Byte offset the signature covers up to. Less
     *                            than the file size means it was signed before
     *                            a later revision was appended.
     * @param  list<Signer>  $signers
     * @param  ?int  $signedAt  The signing time the signer claimed, or null when
     *                          the CMS carries no such attribute. It is signed
     *                          by the signer and taken from their own clock, so
     *                          it says what they asserted rather than when the
     *                          bytes existed. Only an RFC 3161 timestamp makes
     *                          the time attributable to a third party.
     */
    public function __construct(
        public bool $verified,
        public array $signers,
        public int $coverageEnd,
        public bool $coversWholeDocument,
        public bool $isTimestamp = false,
        public ?string $error = null,
        public ?int $signedAt = null,
    ) {}

    /**
     * Whether the signer's certificate was inside its validity window at the
     * moment the signature claims to have been made.
     *
     * Null when either date is unknown, deliberately: a signature with no
     * signing time is not a signature made outside the window, and answering
     * false would report an absence as a violation.
     */
    public function signerWasValidWhenSigned(): ?bool
    {
        $signer = $this->signer();

        if ($this->signedAt === null || $signer === null) {
            return null;
        }

        if ($signer->validFrom === null || $signer->validTo === null) {
            return null;
        }

        return $this->signedAt >= $signer->validFrom && $this->signedAt <= $signer->validTo;
    }

    /**
     * An archive timestamp is not a signature over the document, so it is
     * reported but does not decide whether the document is valid.
     *
     * It is verified on its own terms: its CMS has to check out and its
     * messageImprint has to be the digest of the range it covers. What it does
     * not carry is a signer, which is why it stays out of isValid().
     */
    public function countsTowardValidity(): bool
    {
        return ! $this->isTimestamp;
    }

    public function signer(): ?Signer
    {
        return $this->signers[0] ?? null;
    }
}
