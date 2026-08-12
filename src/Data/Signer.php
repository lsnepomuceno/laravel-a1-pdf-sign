<?php

namespace LSNepomuceno\LaravelA1PdfSign\Data;

use LSNepomuceno\LaravelA1PdfSign\Certificates\IcpBrasilReader;

/**
 * Who signed, as read from the certificate embedded in the signature.
 */
final readonly class Signer extends BaseData
{
    /**
     * @param  array<string, mixed>  $subject
     * @param  array<string, mixed>  $issuer
     * @param  ?IcpBrasilIdentity  $icpBrasil  Who this is under ICP-Brasil, when
     *                                         the certificate was read from
     *                                         bytes rather than only from a
     *                                         parse. Null means "not looked
     *                                         for"; a type of `None` means
     *                                         "looked for and not there".
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
        public ?IcpBrasilIdentity $icpBrasil = null,
    ) {}

    /**
     * The name without the CPF an ICP-Brasil common name carries after a colon.
     *
     * `JOAO DA SILVA:01672780838` is the format the Receita Federal fixes for
     * an e-CPF, and a caller wanting to show a name should not have to know
     * that. The whole value is returned unchanged for any other certificate.
     */
    public function name(): ?string
    {
        if ($this->commonName === null) {
            return null;
        }

        return (string) preg_replace('/:\d{11,14}$/', '', $this->commonName);
    }

    /**
     * @param  array<string, mixed>  $parsed  Output of openssl_x509_parse() with long names.
     * @param  ?string  $pem  The certificate the parse came from, when the
     *                        caller still has it. Without it the ICP-Brasil
     *                        identity cannot be read, because
     *                        openssl_x509_parse() renders those fields as
     *                        `othername:<unsupported>`.
     */
    public static function fromParsedCertificate(array $parsed, ?string $pem = null): self
    {
        /** @var array<string, mixed> $subject */
        $subject = is_array($parsed['subject'] ?? null) ? $parsed['subject'] : [];
        /** @var array<string, mixed> $issuer */
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
            icpBrasil: $pem === null ? null : new IcpBrasilReader()->read($pem),
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
