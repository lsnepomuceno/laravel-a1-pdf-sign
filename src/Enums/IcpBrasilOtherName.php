<?php

namespace LSNepomuceno\LaravelA1PdfSign\Enums;

/**
 * The `otherName` fields an ICP-Brasil certificate carries in its
 * subjectAlternativeName, under the 2.16.76.1.3 arc.
 *
 * The identity a Brazilian signer is known by does not live in the subject. The
 * CPF is glued onto the end of the common name as `NAME:00000000000`, and
 * everything structured (the registry numbers, the birth date, the company)
 * lives here, in fixed-width strings inside an OCTET STRING.
 *
 * Layouts are from the Receita Federal's certificate specification, §2.2.5 for
 * e-CPF and §3.2.5 for e-CNPJ, which restates DOC-ICP-04.
 *
 * See docs/decisions/0029-the-identity-a-brazilian-signer-is-known-by.md.
 */
enum IcpBrasilOtherName: string
{
    /** e-CPF, mandatory: birth date, CPF, NIS, RG and the RG's issuer. */
    case HolderData = '2.16.76.1.3.1';

    /** e-CNPJ, mandatory: the name of the person responsible for the company. */
    case ResponsibleName = '2.16.76.1.3.2';

    /** e-CNPJ, mandatory: the company's CNPJ. */
    case CompanyRegistry = '2.16.76.1.3.3';

    /** e-CNPJ, mandatory: the same layout as HolderData, for that person. */
    case ResponsibleData = '2.16.76.1.3.4';

    /** e-CPF, mandatory: voter registration, its zone, section and municipality. */
    case VoterRegistration = '2.16.76.1.3.5';

    /** e-CPF, mandatory: the holder's INSS specific registry. */
    case HolderSocialSecurity = '2.16.76.1.3.6';

    /** e-CNPJ, mandatory: the company's INSS specific registry. */
    case CompanySocialSecurity = '2.16.76.1.3.7';

    /**
     * The widths this field is made of, in order, or null when it is free text.
     *
     * Fixed width is the whole grammar: there are no separators, so a field
     * read one character short reads the next one wrong rather than failing.
     *
     * @return array<string, int>|null
     */
    public function layout(): ?array
    {
        return match ($this) {
            self::HolderData, self::ResponsibleData => [
                'birthDate' => 8,
                'cpf' => 11,
                'socialIdentity' => 11,
                'nationalId' => 15,
                // "The 6 positions for the issuer and state refer to the maximum
                // size, and only the positions needed are used, left to right",
                // so this one is short in real certificates and is read as the
                // remainder rather than by its width.
                'nationalIdIssuer' => 6,
            ],
            self::VoterRegistration => [
                'voterRegistration' => 12,
                'voterZone' => 3,
                'voterSection' => 4,
                'voterMunicipality' => 22,
            ],
            self::CompanyRegistry => ['cnpj' => 14],
            self::HolderSocialSecurity, self::CompanySocialSecurity => ['socialSecurity' => 12],
            self::ResponsibleName => null,
        };
    }

    /**
     * Whether an e-CPF is required to carry this field.
     */
    public function requiredForIndividual(): bool
    {
        return in_array($this, [self::HolderData, self::VoterRegistration, self::HolderSocialSecurity], true);
    }

    /**
     * Whether an e-CNPJ is required to carry this field.
     */
    public function requiredForLegalEntity(): bool
    {
        return in_array(
            $this,
            [self::ResponsibleName, self::CompanyRegistry, self::ResponsibleData, self::CompanySocialSecurity],
            true,
        );
    }
}
