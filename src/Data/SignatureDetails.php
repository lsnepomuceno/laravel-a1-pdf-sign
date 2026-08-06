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
        public ?string $error = null,
    ) {}

    public function signer(): ?Signer
    {
        return $this->signers[0] ?? null;
    }
}
