<?php

namespace LSNepomuceno\LaravelA1PdfSign\Data;

use LSNepomuceno\LaravelA1PdfSign\Enums\IcpBrasilCertificateType;

/**
 * Who an ICP-Brasil certificate says its holder is.
 *
 * Every value here is **what the certificate carries**, parsed rather than
 * verified against any registry. A CPF that passes its check digits is a
 * well-formed CPF, not a CPF that exists, and neither is evidence that the
 * person it names controls the key: that is the trust question, answered by
 * `Validation\TrustStore` and by nothing here
 * (docs/decisions/0029-the-identity-a-brazilian-signer-is-known-by.md).
 *
 * A field the certificate fills with zeros, which the specification requires
 * when a number is unavailable, is reported as null. "Absent" and "eleven
 * zeros" are the same fact and only one of them is worth handing to a caller.
 */
final readonly class IcpBrasilIdentity extends BaseData
{
    /**
     * @param  ?string  $cpf  The holder's, for an e-CPF; the responsible
     *                        person's, for an e-CNPJ. Eleven digits, unpunctuated.
     * @param  ?string  $cnpj  Fourteen digits, unpunctuated. Null for an e-CPF.
     * @param  ?string  $birthDate  The holder's, as `dd/mm/yyyy`, or null when
     *                              the field is not a date.
     * @param  ?string  $socialIdentity  NIS, which is PIS, PASEP or CI.
     * @param  ?string  $nationalId  RG, with the leading zeros the layout pads
     *                               it to removed.
     * @param  ?string  $nationalIdIssuer  The RG's issuing authority and state,
     *                                     as written.
     * @param  ?string  $socialSecurity  CEI, the INSS specific registry.
     * @param  ?string  $responsibleName  For an e-CNPJ, who answers for the
     *                                    company. Null for an e-CPF.
     * @param  array<string, string>  $raw  Every otherName found, by OID, as it
     *                                      was written. The escape hatch for a
     *                                      field this package does not model.
     */
    public function __construct(
        public IcpBrasilCertificateType $type,
        public ?string $cpf = null,
        public ?string $cnpj = null,
        public ?string $birthDate = null,
        public ?string $socialIdentity = null,
        public ?string $nationalId = null,
        public ?string $nationalIdIssuer = null,
        public ?string $socialSecurity = null,
        public ?string $responsibleName = null,
        public ?string $voterRegistration = null,
        public ?string $voterZone = null,
        public ?string $voterSection = null,
        public ?string $voterMunicipality = null,
        public array $raw = [],
    ) {}

    /**
     * Nothing ICP-Brasil was found, which is the ordinary case for a
     * certificate from anywhere else.
     */
    public static function none(): self
    {
        return new self(IcpBrasilCertificateType::None);
    }

    /**
     * The document that identifies the holder: the company's CNPJ when there is
     * one, and the person's CPF otherwise.
     *
     * An e-CNPJ carries both, and they name different parties: the CNPJ is the
     * holder, the CPF is whoever answers for it.
     */
    public function registry(): ?string
    {
        return $this->cnpj ?? $this->cpf;
    }

    /**
     * The registry punctuated the way it is written in Brazil.
     */
    public function formattedRegistry(): ?string
    {
        return match (true) {
            $this->cnpj !== null => preg_replace('/^(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})$/', '$1.$2.$3/$4-$5', $this->cnpj),
            $this->cpf !== null => preg_replace('/^(\d{3})(\d{3})(\d{3})(\d{2})$/', '$1.$2.$3-$4', $this->cpf),
            default => null,
        };
    }
}
