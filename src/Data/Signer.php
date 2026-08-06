<?php

namespace LSNepomuceno\LaravelA1PdfSign\Data;

/**
 * Who signed, as read from the certificate embedded in the signature.
 */
final readonly class Signer extends BaseData
{
    /**
     * @param  array<string, mixed>  $subject
     * @param  array<string, mixed>  $issuer
     */
    public function __construct(
        public ?string $commonName,
        public ?string $organization,
        public ?string $organizationalUnit,
        public ?string $email,
        public ?string $serialNumber,
        public ?int $validFrom,
        public ?int $validTo,
        public array $subject = [],
        public array $issuer = [],
    ) {}

    /**
     * @param  array<string, mixed>  $parsed  Output of openssl_x509_parse() with long names.
     */
    public static function fromParsedCertificate(array $parsed): self
    {
        $subject = is_array($parsed['subject'] ?? null) ? $parsed['subject'] : [];
        $issuer = is_array($parsed['issuer'] ?? null) ? $parsed['issuer'] : [];

        return new self(
            commonName: self::string($subject, 'commonName'),
            organization: self::string($subject, 'organizationName'),
            organizationalUnit: self::string($subject, 'organizationalUnitName'),
            email: self::string($subject, 'emailAddress'),
            serialNumber: self::string($parsed, 'serialNumberHex') ?? self::string($parsed, 'serialNumber'),
            validFrom: is_int($parsed['validFrom_time_t'] ?? null) ? $parsed['validFrom_time_t'] : null,
            validTo: is_int($parsed['validTo_time_t'] ?? null) ? $parsed['validTo_time_t'] : null,
            subject: $subject,
            issuer: $issuer,
        );
    }

    public function issuerName(): ?string
    {
        $name = $this->issuer['commonName']
            ?? $this->issuer['organizationalUnitName']
            ?? $this->issuer['organizationName']
            ?? null;

        return is_string($name) ? $name : null;
    }

    /**
     * Whether the certificate was already expired at $at.
     */
    public function isExpired(?int $at = null): bool
    {
        return $this->validTo !== null && $this->validTo < ($at ?? time());
    }

    /**
     * @param  array<string, mixed>  $source
     */
    private static function string(array $source, string $key): ?string
    {
        $value = $source[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
