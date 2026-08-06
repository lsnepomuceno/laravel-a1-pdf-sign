<?php

namespace LSNepomuceno\LaravelA1PdfSign\Data;

use OpenSSLCertificate;
use SensitiveParameter;

/**
 * A PKCS#12 certificate after conversion and parsing.
 */
readonly class Certificate extends BaseData
{
    /**
     * @param  string  $original  The combined certificate and private key, in PEM.
     * @param  OpenSSLCertificate|bool  $openssl  The parsed x509 handle, or false when invalid.
     * @param  array<string, mixed>  $data  The output of openssl_x509_parse().
     */
    public function __construct(
        public string $original,
        public OpenSSLCertificate|bool $openssl,
        public array $data,
        #[SensitiveParameter]
        public string $password,
    ) {}

    /**
     * Unix timestamp at which the certificate expires.
     */
    public function expiresAt(): ?int
    {
        $validTo = $this->data['validTo_time_t'] ?? null;

        return is_int($validTo) ? $validTo : null;
    }

    public function isExpired(): bool
    {
        $expiresAt = $this->expiresAt();

        return $expiresAt !== null && $expiresAt < time();
    }

    /**
     * The subject's common name, falling back to the organisation name.
     */
    public function commonName(): ?string
    {
        $subject = $this->data['subject'] ?? [];

        if (! is_array($subject)) {
            return null;
        }

        $name = $subject['commonName'] ?? $subject['organizationName'] ?? null;

        return is_string($name) ? $name : null;
    }
}
