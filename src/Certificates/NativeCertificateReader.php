<?php

namespace LSNepomuceno\LaravelA1PdfSign\Certificates;

use LSNepomuceno\LaravelA1PdfSign\Contracts\CertificateReader;
use LSNepomuceno\LaravelA1PdfSign\Data\Certificate;
use LSNepomuceno\LaravelA1PdfSign\Exceptions\InvalidCertificateContentException;
use SensitiveParameter;

/**
 * Reads a PKCS#12 bundle through ext-openssl.
 *
 * This is the default, and it fixes three problems at once (§1.1): the
 * password never reaches a command line where `ps` can read it, the private
 * key is never written to disk, and the package stops needing an `openssl`
 * binary on PATH.
 *
 * It cannot read legacy bundles (RC2/40-bit) under OpenSSL 3.x, because PHP
 * exposes no equivalent of the CLI's -legacy flag. Those fall back to
 * {@see OpenSslCliCertificateReader}.
 */
final readonly class NativeCertificateReader implements CertificateReader
{
    public function __construct(private CertificateParser $parser) {}

    public function read(
        string $contents,
        #[SensitiveParameter]
        string $password,
    ): Certificate {
        // Drain any error left by an earlier call, so the message we surface
        // belongs to this one.
        while (openssl_error_string() !== false) {
            continue;
        }

        $parsed = [];

        if (! openssl_pkcs12_read($contents, $parsed, $password)) {
            $error = openssl_error_string();

            throw new InvalidCertificateContentException(
                'Unable to read the PKCS#12 bundle: '
                . ($error === false ? 'wrong password or unsupported encryption' : $error),
            );
        }

        /** @var array{cert?: string, pkey?: string, extracerts?: array<int, string>} $parsed */
        return $this->parser->parse($this->toPem($parsed), $password);
    }

    /**
     * Rebuilds the bundle as the combined PEM the signer expects.
     *
     * The order (certificate, private key, then the CA chain) matches what
     * `openssl pkcs12 -nodes` writes, so output stays interchangeable with the
     * legacy reader's.
     *
     * @param  array{cert?: string, pkey?: string, extracerts?: array<int, string>}  $parsed
     */
    private function toPem(array $parsed): string
    {
        $parts = [
            $parsed['cert'] ?? '',
            $parsed['pkey'] ?? '',
            ...($parsed['extracerts'] ?? []),
        ];

        return implode('', array_map(
            static fn(string $part): string => rtrim($part, "\n") . "\n",
            array_filter($parts, static fn(string $part): bool => trim($part) !== ''),
        ));
    }
}
