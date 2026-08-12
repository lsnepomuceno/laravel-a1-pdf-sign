<?php

namespace LSNepomuceno\LaravelA1PdfSign\Certificates;

use LSNepomuceno\LaravelA1PdfSign\Data\IcpBrasilIdentity;
use LSNepomuceno\LaravelA1PdfSign\Enums\IcpBrasilCertificateType;
use LSNepomuceno\LaravelA1PdfSign\Enums\IcpBrasilOtherName;

/**
 * Reads the identity an ICP-Brasil certificate carries.
 *
 * The fields are fixed-width strings with no separators, so every value is a
 * slice at a documented offset. There is one exception, and the specification
 * makes it: the RG's issuing authority occupies "the positions needed, left to
 * right" of six, so it is read as whatever remains rather than by its width.
 *
 * Nothing here decides whether the certificate is well formed. Slicing is a
 * different job from judging, and mixing them would leave a caller unable to
 * read a field this package disapproved of
 * (docs/decisions/0029-the-identity-a-brazilian-signer-is-known-by.md).
 *
 * @internal
 */
final readonly class IcpBrasilReader
{
    public function __construct(private SubjectAlternativeNameReader $names = new SubjectAlternativeNameReader()) {}

    /**
     * @param  string  $certificate  PEM or DER.
     */
    public function read(string $certificate): IcpBrasilIdentity
    {
        $found = $this->names->otherNames($certificate);
        $fields = $this->fields($found);
        $type = $this->type($found);

        if ($type === IcpBrasilCertificateType::None) {
            return IcpBrasilIdentity::none();
        }

        return new IcpBrasilIdentity(
            type: $type,
            cpf: $this->digits($fields['cpf'] ?? null, 11),
            cnpj: $this->digits($fields['cnpj'] ?? null, 14),
            birthDate: $this->birthDate($fields['birthDate'] ?? null),
            socialIdentity: $this->digits($fields['socialIdentity'] ?? null, 11),
            nationalId: $this->unpadded($fields['nationalId'] ?? null),
            nationalIdIssuer: $this->text($fields['nationalIdIssuer'] ?? null),
            socialSecurity: $this->digits($fields['socialSecurity'] ?? null, 12),
            responsibleName: $this->text($found[IcpBrasilOtherName::ResponsibleName->value] ?? null),
            voterRegistration: $this->unpadded($fields['voterRegistration'] ?? null),
            voterZone: $this->unpadded($fields['voterZone'] ?? null),
            voterSection: $this->unpadded($fields['voterSection'] ?? null),
            voterMunicipality: $this->text($fields['voterMunicipality'] ?? null),
            raw: $found,
        );
    }

    /**
     * Every field of every known otherName, sliced by its layout.
     *
     * Exposed so the validator can judge what was written rather than what this
     * class made of it: a CPF the reader rejected as malformed would otherwise
     * be indistinguishable from one that was absent.
     *
     * @param  array<string, string>  $found
     * @return array<string, string>
     */
    public function fields(array $found): array
    {
        $fields = [];

        foreach ($found as $oid => $value) {
            $name = IcpBrasilOtherName::tryFrom($oid);
            $layout = $name?->layout();

            if ($layout === null) {
                continue;
            }

            $offset = 0;
            $last = array_key_last($layout);

            foreach ($layout as $field => $width) {
                // The last slice takes the remainder. Only one field is allowed
                // to be short, and it is always the last one in its layout.
                $fields[$field] = $field === $last
                    ? substr($value, $offset)
                    : substr($value, $offset, $width);

                $offset += $width;
            }
        }

        return $fields;
    }

    /**
     * Which profile this is, from the fields that only one of them carries.
     *
     * A CNPJ decides it: an e-CNPJ carries a CPF too, for whoever answers for
     * the company, so testing for a CPF first would read every company
     * certificate as a person.
     *
     * @param  array<string, string>  $found
     */
    private function type(array $found): IcpBrasilCertificateType
    {
        return match (true) {
            isset($found[IcpBrasilOtherName::CompanyRegistry->value]) => IcpBrasilCertificateType::LegalEntity,
            isset($found[IcpBrasilOtherName::HolderData->value]) => IcpBrasilCertificateType::Individual,
            default => IcpBrasilCertificateType::None,
        };
    }

    /**
     * A number of exactly $length digits, or null.
     *
     * "Unavailable" is written as every character being zero, which the
     * specification requires, so it comes back as null rather than as a string
     * of zeros a caller would have to know to test for.
     */
    private function digits(?string $value, int $length): ?string
    {
        if ($value === null || preg_match('/^\d{' . $length . '}$/', $value) !== 1) {
            return null;
        }

        return trim($value, '0') === '' ? null : $value;
    }

    /**
     * A number padded with leading zeros to its maximum width, with the padding
     * removed. Null when nothing was written.
     */
    private function unpadded(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = ltrim(trim($value), '0');

        return $trimmed === '' ? null : $trimmed;
    }

    private function text(?string $value): ?string
    {
        $trimmed = $value === null ? '' : trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * `ddmmyyyy`, as the layout fixes it, rendered the way it is written.
     *
     * Kept as a string rather than a date: an unparseable one still tells a
     * caller what the certificate said, and a null date does not.
     */
    private function birthDate(?string $value): ?string
    {
        if ($value === null || preg_match('/^(\d{2})(\d{2})(\d{4})$/', $value, $parts) !== 1) {
            return null;
        }

        return checkdate((int) $parts[2], (int) $parts[1], (int) $parts[3])
            ? "{$parts[1]}/{$parts[2]}/{$parts[3]}"
            : null;
    }
}
