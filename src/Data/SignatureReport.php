<?php

namespace LSNepomuceno\LaravelA1PdfSign\Data;

use LSNepomuceno\LaravelA1PdfSign\Enums\CertificationLevel;

/**
 * The outcome of inspecting a document's signatures.
 *
 * In 1.x this reported "validated" when the embedded certificate happened to
 * carry an OU or CN field, which said nothing about whether the signature
 * matched the document. It now reports whether each signature verifies
 * cryptographically against the bytes it covers.
 *
 * That is still narrower than "this document is trustworthy": the issuer is
 * not checked against any trust store, so a valid signature from an unknown
 * authority reports as verified. Trust is the application's call.
 */
final readonly class SignatureReport extends BaseData
{
    /**
     * @param  list<SignatureDetails>  $signatures
     */
    public function __construct(
        public array $signatures,
        public ?SecurityStore $securityStore = null,
        public ?CertificationLevel $certification = null,
    ) {}

    /**
     * Whether the document's author certified it, ISO 32000-1 §12.8.2.2.
     *
     * A certification is a different claim from an approval signature: it says
     * what may happen to the document from here on, not what the bytes were. A
     * reader that honours it will refuse the changes the level forbids, which
     * is why the report says whether one is there rather than leaving the
     * caller to look for /Perms
     * (docs/decisions/0012-certification-signatures.md).
     */
    public function isCertified(): bool
    {
        return $this->certification !== null;
    }

    /**
     * Whether this document can still be signed.
     *
     * False only for a certification at no-changes, where a further signature
     * would be a further revision and that is exactly what was forbidden.
     */
    public function acceptsFurtherSignatures(): bool
    {
        return $this->certification?->allowsFurtherSignatures() ?? true;
    }

    /**
     * Whether the document carries validation material for every signature in
     * it, which is what B-LT promises and what makes a signature checkable
     * after its certificate expires.
     */
    public function hasLongTermMaterial(): bool
    {
        if ($this->securityStore === null || $this->securityStore->isEmpty()) {
            return false;
        }

        foreach ($this->signatures as $signature) {
            if ($signature->countsTowardValidity() && ! $this->securityStore->covers($signature)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether every signature chains to an authority the caller trusts.
     *
     * **Null when no trust store was given**, which is not the same as false.
     * A signature whose issuer nobody was asked about is unknown, and reporting
     * it as untrusted would answer a question that was never put
     * (docs/decisions/0016-trust-is-the-applications-policy.md).
     */
    public function isTrusted(): ?bool
    {
        $checked = array_filter(
            $this->signatures,
            static fn(SignatureDetails $signature): bool => $signature->countsTowardValidity(),
        );

        if ($checked === []) {
            return null;
        }

        foreach ($checked as $signature) {
            if ($signature->isTrusted === null) {
                return null;
            }

            if ($signature->isTrusted === false) {
                return false;
            }
        }

        return true;
    }

    public static function empty(): self
    {
        return new self([]);
    }

    /**
     * True when the document carries at least one signature and every one of
     * them verifies.
     */
    public function isValid(): bool
    {
        if ($this->signatures === []) {
            return false;
        }

        $decisive = 0;

        foreach ($this->signatures as $signature) {
            if (! $signature->countsTowardValidity()) {
                continue;
            }

            if (! $signature->verified) {
                return false;
            }

            $decisive++;
        }

        return $decisive > 0;
    }

    public function isSigned(): bool
    {
        return $this->signatures !== [];
    }

    public function count(): int
    {
        return count($this->signatures);
    }

    /**
     * Every signer across every signature, in signing order.
     *
     * @return list<Signer>
     */
    public function signers(): array
    {
        if ($this->signatures === []) {
            return [];
        }

        return array_merge(...array_map(
            static fn(SignatureDetails $signature): array => $signature->signers,
            $this->signatures,
        ));
    }

    /**
     * The signature applied last, which is the only one covering the whole file.
     */
    /**
     * The archive timestamps, which are reported separately from signatures.
     *
     * @return list<SignatureDetails>
     */
    public function timestamps(): array
    {
        return array_values(array_filter(
            $this->signatures,
            static fn(SignatureDetails $signature): bool => $signature->isTimestamp,
        ));
    }

    public function latest(): ?SignatureDetails
    {
        return $this->signatures === [] ? null : $this->signatures[array_key_last($this->signatures)];
    }
}
