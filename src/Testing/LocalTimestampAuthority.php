<?php

namespace LSNepomuceno\LaravelA1PdfSign\Testing;

use LSNepomuceno\LaravelA1PdfSign\Contracts\SignatureTransport;
use LSNepomuceno\LaravelA1PdfSign\Exceptions\ProcessRunTimeException;
use LSNepomuceno\LaravelA1PdfSign\Support\ProcessRunner;
use LSNepomuceno\LaravelA1PdfSign\Support\TemporaryFile;

/**
 * A timestamp authority that answers without a network, for tests.
 *
 * Everything `pades-b-t` and above adds rides through `SignatureTransport`, and
 * until this existed the only way to exercise any of it was to reach
 * freetsa.org. That put the package's most important behaviour in the `network`
 * group: reported, never blocking, and dependent on somebody else's uptime.
 *
 * `openssl ts -reply` is a full RFC 3161 responder that needs no server and no
 * connection, so a test can hold one of these and gate B-T, B-LT, B-LTA and the
 * archive chain like anything else. It answers with **real tokens**: signed,
 * verifiable, and carrying the imprint of the bytes it was handed. What it is
 * not is a third party, which is the one thing the live tests still establish
 * and this deliberately does not.
 *
 * Test-only, and kept out of the production classes, exactly as
 * `DebugCertificate` is. Nothing in the signing or validation paths refers to
 * it.
 *
 * See docs/decisions/0027-the-transport-is-a-seam.md.
 */
final class LocalTimestampAuthority implements SignatureTransport
{
    private ?string $directory = null;

    public function __construct(
        private readonly ProcessRunner $processes,
        private readonly string $workingPath = '',
    ) {}

    /**
     * @return callable(string): string
     */
    public function timestamp(string $url, ?string $username = null, ?string $password = null): callable
    {
        return function (string $request): string {
            $directory = $this->authority();

            return TemporaryFile::with($directory, '.tsq', $request, function (TemporaryFile $query) use ($directory): string {
                return TemporaryFile::with($directory, '.tsr', '', function (TemporaryFile $reply) use ($query, $directory): string {
                    $this->processes->run(sprintf(
                        'openssl ts -reply -config %s -section tsa_config -queryfile %s -out %s 2>&1',
                        escapeshellarg($directory . 'tsa.cnf'),
                        escapeshellarg($query->path),
                        escapeshellarg($reply->path),
                    ));

                    $token = (string) file_get_contents($reply->path);

                    if ($token === '') {
                        throw new ProcessRunTimeException('the local timestamp authority produced nothing');
                    }

                    return $token;
                });
            });
        };
    }

    /**
     * No responder, which is what a self-signed certificate has anyway.
     *
     * Answering false rather than inventing a response keeps B-LT honest: the
     * store carries the chain and no revocation material, which is exactly what
     * the live tests produce too.
     *
     * @return callable(string, string): (string|false)
     */
    public function ocsp(): callable
    {
        return static fn(string $url, string $request): false => false;
    }

    /**
     * @return callable(string): (string|false)
     */
    public function crl(): callable
    {
        return static fn(string $url): false => false;
    }

    /**
     * The certificate, key and configuration the responder signs with.
     *
     * Built once per instance and left in a temporary directory. The
     * certificate carries the `timeStamping` extended key usage, which
     * RFC 3161 §2.3 requires of a TSA and without which `openssl ts` refuses to
     * sign at all.
     *
     * @throws ProcessRunTimeException
     */
    private function authority(): string
    {
        if ($this->directory !== null) {
            return $this->directory;
        }

        $directory = rtrim($this->workingPath === '' ? sys_get_temp_dir() : $this->workingPath, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . 'a1-pdf-sign-tsa-' . bin2hex(random_bytes(6)) . DIRECTORY_SEPARATOR;

        if (! is_dir($directory) && ! mkdir($directory, 0o700, true) && ! is_dir($directory)) {
            throw new ProcessRunTimeException("could not create {$directory}");
        }

        $this->processes->run(sprintf(
            'openssl req -x509 -newkey rsa:2048 -keyout %s -out %s -days 3650 -nodes '
            . '-subj "/C=BR/O=A1 Pdf Sign/CN=Local Test Timestamp Authority" '
            . '-addext "basicConstraints=critical,CA:FALSE" '
            . '-addext "keyUsage=critical,digitalSignature" '
            . '-addext "extendedKeyUsage=critical,timeStamping" 2>&1',
            escapeshellarg($directory . 'tsa.key'),
            escapeshellarg($directory . 'tsa.pem'),
        ));

        file_put_contents($directory . 'tsa-serial', "01\n");
        file_put_contents($directory . 'tsa.cnf', $this->configuration($directory));

        $this->directory = $directory;

        return $directory;
    }

    private function configuration(string $directory): string
    {
        // ess_cert_id_alg is named explicitly: OpenSSL 3 warns about the
        // missing key otherwise, and a warning on stderr is noise a caller
        // reading the reply does not need.
        return <<<CONFIG
            [tsa]
            default_tsa = tsa_config

            [tsa_config]
            serial = {$directory}tsa-serial
            crypto_device = builtin
            signer_cert = {$directory}tsa.pem
            certs = {$directory}tsa.pem
            signer_key = {$directory}tsa.key
            signer_digest = sha256
            default_policy = 1.3.6.1.4.1.99999.1.1
            digests = sha256, sha384, sha512
            accuracy = secs:1
            clock_precision_digits = 0
            ordering = yes
            tsa_name = yes
            ess_cert_id_chain = no
            ess_cert_id_alg = sha256
            CONFIG;
    }
}
