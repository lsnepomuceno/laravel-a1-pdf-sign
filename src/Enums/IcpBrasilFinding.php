<?php

declare(strict_types=1);

namespace LSNepomuceno\LaravelA1PdfSign\Enums;

/**
 * What a structural check of an ICP-Brasil certificate can find wrong.
 *
 * **Structural means decidable from the certificate alone.** Every case here is
 * a rule the specification states about the bytes: a field that must be
 * present, a width, an alphabet, a check digit, two places that must agree.
 *
 * None of it says the certificate is trustworthy. A self-signed certificate can
 * be built to satisfy every one of these, which is exactly why the trust
 * question stays where it was
 * ([0016](../../docs/decisions/0016-trust-is-the-applications-policy.md)).
 */
enum IcpBrasilFinding: string
{
    case MissingRequiredField = 'missing-required-field';

    case UnexpectedFieldLength = 'unexpected-field-length';

    case IllegalCharacter = 'illegal-character';

    case InvalidCpfCheckDigits = 'invalid-cpf-check-digits';

    case InvalidCnpjCheckDigits = 'invalid-cnpj-check-digits';

    case ImplausibleBirthDate = 'implausible-birth-date';

    case CommonNameDisagreesWithCpf = 'common-name-disagrees-with-cpf';

    case IssuerNamedWithoutNationalId = 'issuer-named-without-national-id';

    /**
     * What the rule says, for a message a caller can show without writing one.
     */
    public function description(): string
    {
        return match ($this) {
            self::MissingRequiredField => 'a field the profile requires is absent from the subject alternative name',
            self::UnexpectedFieldLength => 'the field is not the width its layout fixes',
            self::IllegalCharacter => 'only A to Z and 0 to 9 are allowed in an otherName field',
            self::InvalidCpfCheckDigits => 'the CPF does not satisfy its own check digits',
            self::InvalidCnpjCheckDigits => 'the CNPJ does not satisfy its own check digits',
            self::ImplausibleBirthDate => 'the birth date is not a date in the ddmmyyyy form the layout fixes',
            self::CommonNameDisagreesWithCpf => 'the CPF in the common name is not the CPF in the subject alternative name',
            self::IssuerNamedWithoutNationalId => 'an issuing authority is named for a national identity number that is absent',
        };
    }
}
