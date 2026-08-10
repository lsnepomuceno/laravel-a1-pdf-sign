<?php

namespace LSNepomuceno\LaravelA1PdfSign\Testing;

use LSNepomuceno\LaravelA1PdfSign\Exceptions\CertificateOutputNotFoundException;
use OpenSSLAsymmetricKey;
use OpenSSLCertificate;
use OpenSSLCertificateSigningRequest;
use RuntimeException;

/**
 * Generates throwaway PKCS#12 bundles for tests.
 *
 * This lived on ManageCert in v1, which meant production code shipped a
 * certificate generator (§1.6). It is also fully native now: v1 shelled out to
 * `openssl req` and `openssl pkcs12 -export`, so running the test suite
 * required the binary on PATH.
 */
final class DebugCertificate
{
    public const string PASSWORD = "example's password with special chars: $ & * ? \" '";

    /**
     * @return array{0: string, 1: string} The PFX bytes and its password.
     *
     * @throws CertificateOutputNotFoundException
     */
    public static function make(int $daysValid = 600): array
    {
        [$key, $x509] = self::generate($daysValid);

        $pfx = '';

        if (! openssl_pkcs12_export($x509, $pfx, $key, self::PASSWORD)) {
            throw new CertificateOutputNotFoundException();
        }

        /** @var string $pfx */
        return [$pfx, self::PASSWORD];
    }

    /**
     * The same certificate as PEM, with the key kept separate.
     *
     * $encryptKey mirrors what a real .pem carries: a passphrase-protected key
     * is the common case, an unencrypted one is legal and frequent. The two
     * behave differently under openssl_x509_check_private_key(), so both are
     * fixtures rather than one (docs/decisions/0007-pem-second-entry-one-pipeline.md).
     *
     * @return array{0: string, 1: string, 2: string} Certificate PEM, private key PEM, and the
     *                                                key's password, empty when it is unencrypted.
     */
    public static function makePem(bool $encryptKey = true, int $daysValid = 600): array
    {
        [$key, $x509] = self::generate($daysValid);

        $certificate = '';

        if (! openssl_x509_export($x509, $certificate)) {
            throw new RuntimeException('Unable to export the test certificate: ' . openssl_error_string());
        }

        $privateKey = '';
        $password = $encryptKey ? self::PASSWORD : '';

        if (! openssl_pkey_export($key, $privateKey, $encryptKey ? $password : null)) {
            throw new RuntimeException('Unable to export the test private key: ' . openssl_error_string());
        }

        /** @var string $certificate */
        /** @var string $privateKey */
        return [$certificate, $privateKey, $password];
    }

    /**
     * A root authority and a signing certificate it issued.
     *
     * The plain make() certificate is self-signed and, like any certificate
     * openssl_csr_sign produces by default, carries basicConstraints CA:FALSE.
     * A strict verifier will not accept it as its own trust anchor, and it is
     * right not to: measured on 2026-08-10, openssl_x509_checkpurpose() refuses
     * it even with the certificate itself supplied as the root. Testing trust
     * against that shape would test the fixture rather than the chain, so this
     * builds the shape a real certificate has
     * (docs/decisions/0016-trust-is-the-applications-policy.md).
     *
     * @return array{0: string, 1: string, 2: string} The PFX bytes, its password,
     *                                                and the root authority in PEM.
     *
     * @throws CertificateOutputNotFoundException
     */
    public static function makeChain(int $daysValid = 600): array
    {
        $rootKey = self::key();
        $rootCsr = self::request(['commonName' => 'Test Root Authority'], $rootKey);

        // v3_ca is what sets basicConstraints CA:TRUE, which is what makes a
        // certificate usable as an anchor at all.
        $root = openssl_csr_sign($rootCsr, null, $rootKey, $daysValid + 365, [
            'digest_alg' => 'sha256',
            'x509_extensions' => 'v3_ca',
        ]);

        if ($root === false) {
            throw new RuntimeException('Unable to build the test root: ' . openssl_error_string());
        }

        $key = self::key();
        $csr = self::request(
            ['commonName' => 'Test Certificate', 'organizationalUnitName' => 'LucasNepomuceno'],
            $key,
        );

        $x509 = openssl_csr_sign($csr, $root, $rootKey, $daysValid, ['digest_alg' => 'sha256']);

        if ($x509 === false) {
            throw new RuntimeException('Unable to issue the test certificate: ' . openssl_error_string());
        }

        $pfx = '';

        // The root travels in the bundle, which is what lets the signature
        // carry its own chain and what a real PFX from an authority does.
        if (! openssl_pkcs12_export($x509, $pfx, $key, self::PASSWORD, ['extracerts' => [$root]])) {
            throw new CertificateOutputNotFoundException();
        }

        $rootPem = '';

        if (! openssl_x509_export($root, $rootPem)) {
            throw new RuntimeException('Unable to export the test root: ' . openssl_error_string());
        }

        /** @var string $pfx */
        /** @var string $rootPem */
        return [$pfx, self::PASSWORD, $rootPem];
    }

    /**
     * A fresh self-signed certificate and the key that signed it.
     *
     * @return array{0: OpenSSLAsymmetricKey, 1: OpenSSLCertificate}
     */
    private static function generate(int $daysValid): array
    {
        $key = self::key();

        $csr = self::request(
            ['commonName' => 'Test Certificate', 'organizationalUnitName' => 'LucasNepomuceno'],
            $key,
        );

        $x509 = openssl_csr_sign($csr, null, $key, $daysValid, ['digest_alg' => 'sha256']);

        if ($x509 === false) {
            throw new RuntimeException('Unable to self-sign the test certificate: ' . openssl_error_string());
        }

        return [$key, $x509];
    }

    private static function key(): OpenSSLAsymmetricKey
    {
        $key = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        if (! $key instanceof OpenSSLAsymmetricKey) {
            throw new RuntimeException('Unable to generate a test key: ' . openssl_error_string());
        }

        return $key;
    }

    /**
     * @param  array<string, string>  $subject
     */
    private static function request(array $subject, OpenSSLAsymmetricKey $key): OpenSSLCertificateSigningRequest
    {
        $csr = openssl_csr_new($subject, $key, ['digest_alg' => 'sha256']);

        if ($csr === false) {
            throw new RuntimeException('Unable to generate a test CSR: ' . openssl_error_string());
        }

        if ($csr === true) {
            throw new RuntimeException('openssl_csr_new returned no signing request');
        }

        return $csr;
    }
}
