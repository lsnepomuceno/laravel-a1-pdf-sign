<?php

namespace LSNepomuceno\LaravelA1PdfSign\Certificates;

use LSNepomuceno\LaravelA1PdfSign\Contracts\CertificateReader;
use LSNepomuceno\LaravelA1PdfSign\Data\Certificate;
use LSNepomuceno\LaravelA1PdfSign\Exceptions\InvalidCertificateContentException;
use LSNepomuceno\LaravelA1PdfSign\Exceptions\InvalidPemContentException;
use LSNepomuceno\LaravelA1PdfSign\Exceptions\InvalidX509PrivateKeyException;
use SensitiveParameter;

/**
 * Reads PEM — the degenerate case of {@see CertificateReader}.
 *
 * The other two readers exist to convert PKCS#12 into PEM before handing it to
 * {@see CertificateParser}. PEM is already that destination format, so this
 * reader has no conversion step: it checks the input is what it claims to be,
 * and delegates. Everything downstream is unchanged, which is the whole point —
 * one pipeline, reached through a second entry (ARCHITECTURE-V2.md §3i).
 *
 * It carries no legacy/native axis, so it is not built by {@see ReaderFactory};
 * its single dependency autowires.
 */
final readonly class PemCertificateReader implements CertificateReader
{
    private const string CERTIFICATE_MARKER = '-----BEGIN CERTIFICATE-----';

    /** Covers PRIVATE KEY, RSA PRIVATE KEY, EC PRIVATE KEY and ENCRYPTED PRIVATE KEY. */
    private const string PRIVATE_KEY_PATTERN = '/-----BEGIN (?:[A-Z0-9]+ )*PRIVATE KEY-----/';

    /** ASN.1 SEQUENCE. Both a DER certificate and a PKCS#12 bundle open with it. */
    private const string DER_PREFIX = "\x30";

    public function __construct(private CertificateParser $parser) {}

    /**
     * Whether these bytes carry a PEM certificate.
     *
     * Callers that accept either encoding — the pdf:sign command, the vault —
     * route on this, so "what counts as PEM" is decided in one place rather
     * than re-implemented per entry point.
     */
    public static function looksLikePem(string $contents): bool
    {
        return str_contains($contents, self::CERTIFICATE_MARKER);
    }

    /**
     * Reads a bundle holding both the certificate and its private key.
     *
     * The password defaults to empty because, unlike PKCS#12, a PEM private key
     * is frequently unencrypted — and OpenSSL ignores a passphrase given for a
     * key that does not need one, so the default is safe either way.
     *
     * @param  string  $contents  A PEM bundle: certificate and private key, in any order.
     *
     * @throws InvalidPemContentException
     * @throws InvalidCertificateContentException
     * @throws InvalidX509PrivateKeyException
     */
    public function read(
        string $contents,
        #[SensitiveParameter]
        string $password = '',
    ): Certificate {
        $this->requireCertificate($contents, 'the bundle');
        $this->requirePrivateKey($contents, 'the bundle');

        return $this->parser->parse($contents, $password);
    }

    /**
     * Reads a certificate and a private key that arrived as separate files.
     *
     * The two are checked separately so the message names the file at fault —
     * passing the same path twice is a real mistake, and it reads as "no
     * private key" rather than as something about the certificate.
     *
     * @throws InvalidPemContentException
     * @throws InvalidCertificateContentException
     * @throws InvalidX509PrivateKeyException
     */
    public function readPair(
        string $certificatePem,
        string $privateKeyPem,
        #[SensitiveParameter]
        string $password = '',
    ): Certificate {
        $this->requireCertificate($certificatePem, 'the certificate');
        $this->requirePrivateKey($privateKeyPem, 'the private key');

        return $this->parser->parse(self::join($certificatePem, $privateKeyPem), $password);
    }

    /**
     * @throws InvalidPemContentException
     */
    private function requireCertificate(string $contents, string $label): void
    {
        if (self::looksLikePem($contents)) {
            return;
        }

        // Binary input is the likeliest mistake here: openssl_x509_read() fails
        // on DER without saying why, and a .pfx handed to the PEM entry point
        // would otherwise be reported as malformed rather than as misrouted.
        throw new InvalidPemContentException(str_starts_with($contents, self::DER_PREFIX)
            ? "Expected PEM in {$label}, found binary DER or PKCS#12 bytes — read those through certificate() instead."
            : "No PEM certificate block found in {$label}.");
    }

    /**
     * @throws InvalidPemContentException
     */
    private function requirePrivateKey(string $contents, string $label): void
    {
        if (preg_match(self::PRIVATE_KEY_PATTERN, $contents) === 1) {
            return;
        }

        throw new InvalidPemContentException(
            "No PEM private key block found in {$label}; signing needs the key, not the certificate alone.",
        );
    }

    /**
     * Mirrors NativeCertificateReader::toPem(), so a bundle assembled here is
     * interchangeable with one that came out of PKCS#12.
     */
    private static function join(string $certificatePem, string $privateKeyPem): string
    {
        return implode('', array_map(
            static fn(string $part): string => rtrim($part, "\n") . "\n",
            [$certificatePem, $privateKeyPem],
        ));
    }
}
