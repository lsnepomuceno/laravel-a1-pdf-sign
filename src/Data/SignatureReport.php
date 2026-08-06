<?php

namespace LSNepomuceno\LaravelA1PdfSign\Data;

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
    public function __construct(public array $signatures) {}

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

        foreach ($this->signatures as $signature) {
            if (! $signature->verified) {
                return false;
            }
        }

        return true;
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
        return array_merge(...array_map(
            static fn(SignatureDetails $signature): array => $signature->signers,
            $this->signatures,
        )) ?: [];
    }

    /**
     * The signature applied last, which is the only one covering the whole file.
     */
    public function latest(): ?SignatureDetails
    {
        return $this->signatures === [] ? null : $this->signatures[array_key_last($this->signatures)];
    }
}
