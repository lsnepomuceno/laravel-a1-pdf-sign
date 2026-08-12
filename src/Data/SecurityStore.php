<?php

declare(strict_types=1);

namespace LSNepomuceno\LaravelA1PdfSign\Data;

/**
 * What a document carries for validating its signatures later.
 *
 * The Document Security Store holds the certificate chain, OCSP responses and
 * CRLs as they stood when the signature was made, so the signature can still be
 * checked once the responders are gone and the certificate has expired. B-LT
 * adds it and B-LTA seals it under an archive timestamp.
 *
 * This describes what is there. It does not say the material is good, which is
 * a further step: docs/decisions/0010-validation-consumes-what-signing-writes.md.
 */
final readonly class SecurityStore extends BaseData
{
    /**
     * @param  list<string>  $signatureKeys  Uppercase hex SHA-1 of each signature's
     *                                       /Contents that has validation material,
     *                                       which is how /VRI names them.
     */
    public function __construct(
        public int $certificates,
        public int $ocspResponses,
        public int $crls,
        public array $signatureKeys = [],
    ) {}

    /**
     * Whether the store carries material for this signature specifically.
     *
     * A store with certificates but no /VRI entry for a given signature is
     * carrying material for a different one, which is the case worth telling
     * apart in a document signed more than once.
     */
    public function covers(SignatureDetails $signature): bool
    {
        return in_array($signature->securityStoreKey(), $this->signatureKeys, true);
    }

    public function isEmpty(): bool
    {
        return $this->certificates === 0 && $this->ocspResponses === 0 && $this->crls === 0;
    }
}
