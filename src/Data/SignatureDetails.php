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
     */
    public function __construct(
        public bool $verified,
        public array $signers,
        public int $coverageEnd,
        public bool $coversWholeDocument,
        public bool $isTimestamp = false,
        public ?string $error = null,
    ) {}

    /**
     * An archive timestamp is not a signature over the document, so it is
     * reported but does not decide whether the document is valid. Its own
     * cryptographic verification is not implemented yet.
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
