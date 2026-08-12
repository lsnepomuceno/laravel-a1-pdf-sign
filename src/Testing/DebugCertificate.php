<?php

namespace LSNepomuceno\LaravelA1PdfSign\Testing;

use LSNepomuceno\LaravelA1PdfSign\Enums\IcpBrasilCertificateType;
use LSNepomuceno\LaravelA1PdfSign\Enums\IcpBrasilOtherName;
use LSNepomuceno\LaravelA1PdfSign\Exceptions\CertificateOutputNotFoundException;
use LSNepomuceno\LaravelA1PdfSign\Support\TemporaryFile;
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
     * A certificate shaped like an ICP-Brasil one, for reading the fields back.
     *
     * **It is self-signed, and that is the point of saying so here.** It
     * carries the `otherName` fields the Receita Federal's layout fixes, so a
     * parser can be tested against something with the right shape, and it
     * chains to nothing: no trust store will accept it, and none should
     * (docs/decisions/0029-the-identity-a-brazilian-signer-is-known-by.md).
     *
     * @param  array<string, string>  $otherNames  OID to written value,
     *                                             replacing the defaults. Pass a
     *                                             malformed one to exercise a
     *                                             finding.
     * @return array{0: string, 1: string} The PFX bytes and its password.
     *
     * @throws CertificateOutputNotFoundException
     */
    public static function icpBrasil(
        IcpBrasilCertificateType $type = IcpBrasilCertificateType::Individual,
        array $otherNames = [],
        string $commonName = 'JOAO DA SILVA:11144477735',
        int $daysValid = 600,
    ): array {
        // An empty override leaves the field out entirely, which is how a test
        // expresses "this certificate does not carry it". An empty otherName is
        // a different thing, and not the one anybody wants to check.
        $fields = array_filter([...self::icpBrasilFields($type), ...$otherNames], static fn(string $value): bool => $value !== '');

        $key = self::key();

        $x509 = self::signWithExtensions(
            self::openSslConfiguration($fields),
            'icp',
            ['commonName' => $commonName, 'countryName' => 'BR'],
            $key,
            $daysValid,
        );

        $pfx = '';

        if (! openssl_pkcs12_export($x509, $pfx, $key, self::PASSWORD)) {
            throw new CertificateOutputNotFoundException();
        }

        /** @var string $pfx */
        return [$pfx, self::PASSWORD];
    }

    /**
     * A certificate that names where its revocation material lives.
     *
     * `Signer::collectValidationMaterial()` reads the endpoints out of the
     * certificate, so a certificate carrying none is never asked about, whatever
     * transport is bound. Without this, the code that gathers a Document
     * Security Store could only be exercised against a real authority
     * (docs/decisions/0022-the-archive-timestamp-is-a-chain.md).
     *
     * The URLs are unroutable on purpose. A substituted transport answers them,
     * and anything that reached the network would be a test lying about what it
     * checks.
     *
     * @return array{0: string, 1: string} The PFX bytes and its password.
     *
     * @throws CertificateOutputNotFoundException
     */
    public static function makeRevocable(
        string $crlUrl = 'http://crl.invalid/test.crl',
        string $ocspUrl = 'http://ocsp.invalid',
        int $daysValid = 600,
    ): array {
        $key = self::key();

        $configuration = implode("\n", [
            '[req]',
            'distinguished_name = dn',
            '[dn]',
            '[leaf]',
            'basicConstraints = CA:FALSE',
            'keyUsage = critical, digitalSignature, nonRepudiation, keyEncipherment',
            "crlDistributionPoints = URI:{$crlUrl}",
            "authorityInfoAccess = OCSP;URI:{$ocspUrl}",
            '',
        ]);

        $x509 = self::signWithExtensions(
            $configuration,
            'leaf',
            ['commonName' => 'Revocable Test Certificate'],
            $key,
            $daysValid,
        );

        $pfx = '';

        if (! openssl_pkcs12_export($x509, $pfx, $key, self::PASSWORD)) {
            throw new CertificateOutputNotFoundException();
        }

        /** @var string $pfx */
        return [$pfx, self::PASSWORD];
    }

    /**
     * Issues a self-signed certificate whose extensions come from a written
     * configuration, which is the only way openssl accepts an `otherName` or a
     * distribution point.
     *
     * @param  array<string, string>  $subject
     */
    private static function signWithExtensions(
        string $configuration,
        string $section,
        array $subject,
        OpenSSLAsymmetricKey $key,
        int $daysValid,
    ): OpenSSLCertificate {
        return TemporaryFile::with(
            sys_get_temp_dir(),
            '.cnf',
            $configuration,
            static function (TemporaryFile $file) use ($section, $subject, $key, $daysValid): OpenSSLCertificate {
                $options = ['digest_alg' => 'sha256', 'config' => $file->path, 'x509_extensions' => $section];

                // openssl_csr_new takes the key by reference, so it is handed a
                // copy: the analyser widens anything that function touches to
                // mixed, and the signing call below needs the type intact.
                $request = $key;
                $csr = openssl_csr_new($subject, $request, $options);

                if (! $csr instanceof OpenSSLCertificateSigningRequest) {
                    throw new RuntimeException('Unable to generate the test CSR: ' . openssl_error_string());
                }

                $signed = openssl_csr_sign($csr, null, $key, $daysValid, $options);

                if ($signed === false) {
                    throw new RuntimeException('Unable to sign the test certificate: ' . openssl_error_string());
                }

                return $signed;
            },
        );
    }

    /**
     * The fields each profile is required to carry, filled with values that
     * satisfy the check digits.
     *
     * @return array<string, string>
     */
    private static function icpBrasilFields(IcpBrasilCertificateType $type): array
    {
        // 8 birth + 11 CPF + 11 NIS + 15 RG + 6 issuer, and "unavailable" is
        // written as zeros rather than left out.
        $holder = '11081985' . '11144477735' . '12345678901' . '000000012345678' . 'SSPSP';

        if ($type === IcpBrasilCertificateType::LegalEntity) {
            return [
                IcpBrasilOtherName::ResponsibleName->value => 'JOAO DA SILVA',
                IcpBrasilOtherName::CompanyRegistry->value => '11222333000181',
                IcpBrasilOtherName::ResponsibleData->value => $holder,
                IcpBrasilOtherName::CompanySocialSecurity->value => '000000000000',
            ];
        }

        return [
            IcpBrasilOtherName::HolderData->value => $holder,
            // 12 registration + 3 zone + 4 section + 22 municipality.
            IcpBrasilOtherName::VoterRegistration->value => '465555610469' . '001' . '0477' . 'SAOPAULOSP',
            IcpBrasilOtherName::HolderSocialSecurity->value => '000000000000',
        ];
    }

    /**
     * An openssl configuration carrying the fields as `otherName` entries.
     *
     * OCTET STRING because the specification says so, in as many words: "the
     * information in each OtherName field shall be stored as an ASN.1 OCTET
     * STRING character string". Real certificates also use UTF8String, and the
     * reader accepts both, so this generates the one the rule names.
     *
     * @param  array<string, string>  $fields
     */
    private static function openSslConfiguration(array $fields): string
    {
        $entries = [];
        $index = 0;

        foreach ($fields as $oid => $value) {
            $index++;
            $entries[] = "otherName.{$index} = {$oid};FORMAT:ASCII,OCTETSTRING:{$value}";
        }

        return implode("\n", [
            '[req]',
            'distinguished_name = dn',
            '[dn]',
            '[icp]',
            'subjectAltName = @alt',
            'basicConstraints = CA:FALSE',
            'keyUsage = critical, digitalSignature, nonRepudiation, keyEncipherment',
            '[alt]',
            ...$entries,
            'email = signer@example.test',
            '',
        ]);
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
