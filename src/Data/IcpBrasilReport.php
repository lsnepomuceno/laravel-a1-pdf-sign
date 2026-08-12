<?php

declare(strict_types=1);

namespace LSNepomuceno\LaravelA1PdfSign\Data;

use LSNepomuceno\LaravelA1PdfSign\Enums\IcpBrasilFinding;

/**
 * What a structural check of an ICP-Brasil certificate found.
 *
 * **`conforms()` is not `isTrusted()`, and confusing the two is the whole risk
 * this class carries.** Every rule behind it is decidable from the certificate
 * alone, so a self-signed certificate built to satisfy them all conforms. What
 * it does not do is chain to an ICP-Brasil root, which is the question
 * `Validation\TrustStore` answers and this one deliberately does not
 * (docs/decisions/0029-the-identity-a-brazilian-signer-is-known-by.md).
 *
 * Useful before trust, not instead of it: a certificate that fails here will
 * not be read correctly by anything, and finding that out from the bytes beats
 * finding it out from a rejected filing.
 */
final readonly class IcpBrasilReport extends BaseData
{
    /**
     * @param  list<array{finding: IcpBrasilFinding, field: string, detail: ?string}>  $findings
     */
    public function __construct(
        public IcpBrasilIdentity $identity,
        public array $findings = [],
    ) {}

    /**
     * Whether the certificate satisfies every structural rule checked.
     *
     * A certificate that is not ICP-Brasil at all does not conform, since there
     * was nothing to conform to. `identity->type` is what separates that from a
     * malformed one.
     */
    public function conforms(): bool
    {
        return $this->identity->type->isIcpBrasil() && $this->findings === [];
    }

    /**
     * Whether anything was found of this kind.
     */
    public function has(IcpBrasilFinding $finding): bool
    {
        foreach ($this->findings as $entry) {
            if ($entry['finding'] === $finding) {
                return true;
            }
        }

        return false;
    }

    /**
     * One line per finding, for a log or a message to whoever sent the file.
     *
     * @return list<string>
     */
    public function messages(): array
    {
        return array_map(
            static fn(array $entry): string => $entry['detail'] === null
                ? "{$entry['field']}: {$entry['finding']->description()}"
                : "{$entry['field']}: {$entry['finding']->description()} ({$entry['detail']})",
            $this->findings,
        );
    }
}
