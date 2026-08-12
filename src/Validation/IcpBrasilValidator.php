<?php

declare(strict_types=1);

namespace LSNepomuceno\LaravelA1PdfSign\Validation;

use LSNepomuceno\LaravelA1PdfSign\Certificates\IcpBrasilReader;
use LSNepomuceno\LaravelA1PdfSign\Certificates\SubjectAlternativeNameReader;
use LSNepomuceno\LaravelA1PdfSign\Data\IcpBrasilReport;
use LSNepomuceno\LaravelA1PdfSign\Enums\IcpBrasilCertificateType;
use LSNepomuceno\LaravelA1PdfSign\Enums\IcpBrasilFinding;
use LSNepomuceno\LaravelA1PdfSign\Enums\IcpBrasilOtherName;
use LSNepomuceno\LaravelA1PdfSign\Support\NationalRegistry;

/**
 * Checks an ICP-Brasil certificate against the rules its own specification
 * states about its bytes.
 *
 * **Structural, and that word is doing real work.** Everything checked here is
 * decidable from the certificate alone: a required field is present, a width is
 * the width the layout fixes, the alphabet is the one allowed, the check digits
 * hold, and the CPF in the common name is the CPF in the extension. None of it
 * is evidence that the certificate is genuine, and a self-signed one can be
 * built to satisfy every rule.
 *
 * What it is good for is the thing that goes wrong in practice: a file arrives,
 * something downstream rejects it, and nobody can say which field was wrong.
 * This says which field, from the certificate, before anything is signed
 * (docs/decisions/0029-the-identity-a-brazilian-signer-is-known-by.md).
 */
final readonly class IcpBrasilValidator
{
    /** Receita Federal §3.2.5: only A to Z and 0 to 9 in an otherName field. */
    private const string ALPHABET = '/^[A-Z0-9]*$/';

    public function __construct(
        private IcpBrasilReader $reader = new IcpBrasilReader(),
        private SubjectAlternativeNameReader $names = new SubjectAlternativeNameReader(),
        private NationalRegistry $registry = new NationalRegistry(),
    ) {}

    /**
     * @param  string  $certificate  PEM or DER.
     * @param  ?string  $commonName  The subject's CN, when the caller has it
     *                               parsed. Without it the cross-check between
     *                               the two places a CPF appears cannot run,
     *                               which is reported by not running rather
     *                               than by failing.
     */
    public function validate(string $certificate, ?string $commonName = null): IcpBrasilReport
    {
        $found = $this->names->otherNames($certificate);
        $identity = $this->reader->read($certificate);

        if (! $identity->type->isIcpBrasil()) {
            return new IcpBrasilReport($identity);
        }

        $fields = $this->reader->fields($found);

        return new IcpBrasilReport($identity, [
            ...$this->requiredFields($identity->type, $found),
            ...$this->alphabet($found),
            ...$this->widths($found),
            ...$this->checkDigits($fields),
            ...$this->birthDate($fields),
            ...$this->nationalId($fields),
            ...$this->commonName($commonName, $fields),
        ]);
    }

    /**
     * @param  array<string, string>  $found
     * @return list<array{finding: IcpBrasilFinding, field: string, detail: ?string}>
     */
    private function requiredFields(IcpBrasilCertificateType $type, array $found): array
    {
        $findings = [];

        foreach (IcpBrasilOtherName::cases() as $name) {
            $required = $type === IcpBrasilCertificateType::Individual
                ? $name->requiredForIndividual()
                : $name->requiredForLegalEntity();

            if ($required && ! isset($found[$name->value])) {
                $findings[] = [
                    'finding' => IcpBrasilFinding::MissingRequiredField,
                    'field' => $name->name,
                    'detail' => $name->value,
                ];
            }
        }

        return $findings;
    }

    /**
     * @param  array<string, string>  $found
     * @return list<array{finding: IcpBrasilFinding, field: string, detail: ?string}>
     */
    private function alphabet(array $found): array
    {
        $findings = [];

        foreach ($found as $oid => $value) {
            $name = IcpBrasilOtherName::tryFrom($oid);

            // A name is free text and still restricted to the same alphabet,
            // which is why accented characters are stripped before issue.
            if ($name !== null && preg_match(self::ALPHABET, str_replace(' ', '', $value)) !== 1) {
                $findings[] = [
                    'finding' => IcpBrasilFinding::IllegalCharacter,
                    'field' => $name->name,
                    'detail' => null,
                ];
            }
        }

        return $findings;
    }

    /**
     * @param  array<string, string>  $found
     * @return list<array{finding: IcpBrasilFinding, field: string, detail: ?string}>
     */
    private function widths(array $found): array
    {
        $findings = [];

        foreach ($found as $oid => $value) {
            $layout = IcpBrasilOtherName::tryFrom($oid)?->layout();

            if ($layout === null) {
                continue;
            }

            $expected = array_sum($layout);
            $last = (int) end($layout);
            $actual = strlen($value);

            // Only the final field may be short, and only that one, so the
            // acceptable range is the full width down to the full width minus
            // that field.
            if ($actual > $expected || $actual < $expected - $last) {
                $findings[] = [
                    'finding' => IcpBrasilFinding::UnexpectedFieldLength,
                    'field' => (string) IcpBrasilOtherName::from($oid)->name,
                    'detail' => "{$actual} characters, expected {$expected}",
                ];
            }
        }

        return $findings;
    }

    /**
     * @param  array<string, string>  $fields
     * @return list<array{finding: IcpBrasilFinding, field: string, detail: ?string}>
     */
    private function checkDigits(array $fields): array
    {
        $findings = [];

        // A field of zeros means "not available", which the specification allows
        // and which no check digit can be asked about.
        if (isset($fields['cpf']) && trim($fields['cpf'], '0') !== '' && ! $this->registry->isCpf($fields['cpf'])) {
            $findings[] = [
                'finding' => IcpBrasilFinding::InvalidCpfCheckDigits,
                'field' => 'cpf',
                'detail' => $fields['cpf'],
            ];
        }

        if (isset($fields['cnpj']) && trim($fields['cnpj'], '0') !== '' && ! $this->registry->isCnpj($fields['cnpj'])) {
            $findings[] = [
                'finding' => IcpBrasilFinding::InvalidCnpjCheckDigits,
                'field' => 'cnpj',
                'detail' => $fields['cnpj'],
            ];
        }

        return $findings;
    }

    /**
     * @param  array<string, string>  $fields
     * @return list<array{finding: IcpBrasilFinding, field: string, detail: ?string}>
     */
    private function birthDate(array $fields): array
    {
        $written = $fields['birthDate'] ?? null;

        if ($written === null || preg_match('/^(\d{2})(\d{2})(\d{4})$/', $written, $parts) !== 1) {
            return $written === null ? [] : [[
                'finding' => IcpBrasilFinding::ImplausibleBirthDate,
                'field' => 'birthDate',
                'detail' => $written,
            ]];
        }

        return checkdate((int) $parts[2], (int) $parts[1], (int) $parts[3]) ? [] : [[
            'finding' => IcpBrasilFinding::ImplausibleBirthDate,
            'field' => 'birthDate',
            'detail' => $written,
        ]];
    }

    /**
     * Receita Federal §3.2.5: "if the RG is unavailable, the issuer and state
     * fields shall not be filled in".
     *
     * @param  array<string, string>  $fields
     * @return list<array{finding: IcpBrasilFinding, field: string, detail: ?string}>
     */
    private function nationalId(array $fields): array
    {
        $number = trim($fields['nationalId'] ?? '', '0 ');
        $issuer = trim($fields['nationalIdIssuer'] ?? '');

        return $number === '' && $issuer !== '' ? [[
            'finding' => IcpBrasilFinding::IssuerNamedWithoutNationalId,
            'field' => 'nationalIdIssuer',
            'detail' => $issuer,
        ]] : [];
    }

    /**
     * The CPF appears twice, in the common name as `NAME:00000000000` and in the
     * extension, and nothing in the format makes them agree.
     *
     * @param  array<string, string>  $fields
     * @return list<array{finding: IcpBrasilFinding, field: string, detail: ?string}>
     */
    private function commonName(?string $commonName, array $fields): array
    {
        $cpf = $fields['cpf'] ?? null;

        if ($commonName === null || $cpf === null || preg_match('/:(\d{11})$/', $commonName, $parts) !== 1) {
            return [];
        }

        return $parts[1] === $cpf ? [] : [[
            'finding' => IcpBrasilFinding::CommonNameDisagreesWithCpf,
            'field' => 'commonName',
            'detail' => "{$parts[1]} against {$cpf}",
        ]];
    }
}
