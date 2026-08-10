<?php

namespace LSNepomuceno\LaravelA1PdfSign\Signing\Cades;

use Com\Tecnick\Pdf\Sign\Signer;
use Com\Tecnick\Pdf\Sign\Timestamp\Client as TimestampClient;
use Com\Tecnick\Pdf\Sign\Timestamp\Config as TimestampConfig;
use Illuminate\Contracts\Config\Repository as Config;
use LSNepomuceno\LaravelA1PdfSign\Data\Certificate;
use LSNepomuceno\LaravelA1PdfSign\Enums\SignatureProfile;
use LSNepomuceno\LaravelA1PdfSign\Exceptions\InvalidCertificateContentException;
use LSNepomuceno\LaravelA1PdfSign\Exceptions\ProcessRunTimeException;
use LSNepomuceno\LaravelA1PdfSign\Support\Pem;
use Throwable;

/**
 * Produces the detached CMS blob embedded in a signature.
 *
 * This replaces openssl_pkcs7_sign(), which cannot emit the ESS
 * signing-certificate-v2 attribute a PAdES baseline signature requires, and
 * which had to be un-wrapped from an S/MIME envelope afterwards. The upstream
 * builder assembles the DER directly.
 */
final readonly class CadesBuilder
{
    public function __construct(
        private Config $config,
        private HttpTransport $transport,
        private Signer $signer = new Signer(),
    ) {}

    /**
     * @param  string  $content  The ByteRange-covered bytes to sign.
     *
     * @throws InvalidCertificateContentException
     * @throws ProcessRunTimeException
     */
    public function build(
        string $content,
        Certificate $certificate,
        SignatureProfile $profile,
    ): string {
        [$certDer, $chainDer] = $this->certificates($certificate);
        $privateKey = $this->privateKey($certificate);

        [$timestampClient, $timestampTransport] = $this->timestamp($profile);

        try {
            return $this->signer->sign(
                $content,
                $certDer,
                $privateKey,
                $chainDer,
                $profile->toCadesConfig($this->digestAlgorithm()),
                time(),
                $timestampClient,
                $timestampTransport,
            );
        } catch (Throwable $exception) {
            throw new ProcessRunTimeException('CAdES signing failed: ' . $exception->getMessage());
        }
    }

    /**
     * The signing certificate in DER, plus any chain certificates found in the
     * bundle.
     *
     * @return array{0: string, 1: list<string>}
     *
     * @throws InvalidCertificateContentException
     */
    private function certificates(Certificate $certificate): array
    {
        $pems = Pem::certificates($certificate->original);

        if ($pems === []) {
            throw new InvalidCertificateContentException('no certificate found in the bundle');
        }

        $der = array_map($this->toDer(...), $pems);

        return [array_shift($der), array_values($der)];
    }

    /**
     * @throws InvalidCertificateContentException
     */
    private function toDer(string $pem): string
    {
        $der = Pem::toDer($pem);

        if ($der === null) {
            throw new InvalidCertificateContentException('a certificate in the bundle is not valid base64');
        }

        return $der;
    }

    /**
     * @throws InvalidCertificateContentException
     */
    private function privateKey(Certificate $certificate): \OpenSSLAsymmetricKey
    {
        $key = openssl_pkey_get_private($certificate->original, $certificate->password);

        if ($key === false) {
            $error = openssl_error_string();

            throw new InvalidCertificateContentException(
                'the private key could not be read: ' . ($error === false ? 'unknown error' : $error),
            );
        }

        return $key;
    }

    /**
     * @return array{0: TimestampClient|null, 1: (callable(string): string)|null}
     *
     * @throws ProcessRunTimeException
     */
    private function timestamp(SignatureProfile $profile): array
    {
        if (! $profile->needsTimestamp()) {
            return [null, null];
        }

        $url = $this->config->get('a1-pdf-sign.signature.timestamp.url');

        if (! is_string($url) || $url === '') {
            throw new ProcessRunTimeException(
                "profile {$profile->value} needs a timestamp authority; set a1-pdf-sign.signature.timestamp.url",
            );
        }

        // Auth and the actual HTTP live in our transport; the upstream config
        // only needs what shapes the TimeStampReq itself.
        $client = new TimestampClient(new TimestampConfig(
            host: $url,
            hashAlgorithm: $this->digestAlgorithm(),
            timeout: max(1, $this->intConfig('signature.timestamp.timeout', 20)),
        ));

        return [
            $client,
            $this->transport->timestamp(
                $url,
                $this->stringConfig('signature.timestamp.username'),
                $this->stringConfig('signature.timestamp.password'),
            ),
        ];
    }

    private function digestAlgorithm(): string
    {
        $algorithm = $this->stringConfig('signature.digest_algorithm') ?? 'sha256';

        return in_array($algorithm, ['sha256', 'sha384', 'sha512'], true) ? $algorithm : 'sha256';
    }

    private function stringConfig(string $key): ?string
    {
        $value = $this->config->get("a1-pdf-sign.{$key}");

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function intConfig(string $key, int $default): int
    {
        $value = $this->config->get("a1-pdf-sign.{$key}", $default);

        return is_numeric($value) ? (int) $value : $default;
    }
}
