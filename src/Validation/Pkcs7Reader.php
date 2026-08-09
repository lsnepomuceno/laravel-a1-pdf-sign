<?php

namespace LSNepomuceno\LaravelA1PdfSign\Validation;

use LSNepomuceno\LaravelA1PdfSign\Data\Signer;

/**
 * Reads the certificates embedded in a detached CMS.
 *
 * 1.x shelled out to `openssl pkcs7 -print_certs` and parsed the human-readable
 * output with three chained preg_replace calls, which broke outright when
 * OpenSSL 3.5 changed its field separator (§1.9, §1.14). Here the DER is
 * scanned for certificate structures and each one is handed to
 * openssl_x509_parse(), so the result is structured data rather than text.
 *
 * Misreading the DER yields no certificates, which is visible. It cannot yield
 * a wrong "valid" verdict: whether a signature verifies is decided elsewhere.
 */
final class Pkcs7Reader
{
    public function __construct(private readonly DerReader $der = new DerReader()) {}

    /**
     * @return list<Signer>
     */
    public function signers(string $der): array
    {
        return array_map(
            static fn(array $parsed): Signer => Signer::fromParsedCertificate($parsed),
            $this->parsedCertificates($der),
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function parsedCertificates(string $der): array
    {
        $parsed = [];

        foreach ($this->certificates($der) as $pem) {
            $data = openssl_x509_parse($pem, false);

            if ($data !== false) {
                /** @var array<string, mixed> $data */
                $parsed[] = $data;
            }
        }

        return $parsed;
    }

    /**
     * Every X.509 certificate in the blob, as PEM.
     *
     * Certificates sit inside the SignedData's certificate set as DER
     * SEQUENCEs. Rather than walking the whole CMS grammar, candidates are
     * offered to openssl_x509_read() and kept when it accepts them: the
     * parser itself decides what is a certificate.
     *
     * @return list<string>
     */
    public function certificates(string $der): array
    {
        $found = [];
        $length = strlen($der);

        for ($offset = 0; $offset < $length - 4; $offset++) {
            // 0x30 0x82 is SEQUENCE with a two-byte length, the shape every
            // real certificate takes.
            if ($der[$offset] !== "\x30" || $der[$offset + 1] !== "\x82") {
                continue;
            }

            $candidate = $this->der->truncate(substr($der, $offset));

            if ($candidate === '') {
                continue;
            }

            $pem = $this->toPem($candidate);

            if (@openssl_x509_read($pem) === false) {
                continue;
            }

            // Keyed by the DER itself, so duplicates collapse without hashing.
            $found[$candidate] = $pem;

            // Skip past the certificate just taken, so its inner sequences are
            // not offered again.
            $offset += strlen($candidate) - 1;
        }

        return array_values($found);
    }

    private function toPem(string $der): string
    {
        return "-----BEGIN CERTIFICATE-----\n"
            . chunk_split(base64_encode($der), 64, "\n")
            . "-----END CERTIFICATE-----\n";
    }
}
