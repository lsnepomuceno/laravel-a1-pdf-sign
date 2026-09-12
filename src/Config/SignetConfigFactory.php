<?php

declare(strict_types=1);

namespace LSNepomuceno\LaravelA1PdfSign\Config;

use Illuminate\Contracts\Config\Repository as Config;
use LSNepomuceno\Signet\Config\{CertificateConfig, LtvConfig, SealConfig, SignetConfig, SigningConfig, TimestampConfig};
use LSNepomuceno\Signet\Enums\{DigestAlgorithm, FontSize, ImageDriver, SignatureProfile};

/**
 * Builds signet-pdf's configuration object out of `config/a1-pdf-sign.php`.
 *
 * The config file stays an array of scalars so `config:cache` keeps working:
 * the objects are built here, at resolution time, rather than written into the
 * file (docs/decisions/0039-the-core-lives-in-signet-pdf.md).
 *
 * A bad value fails here, naming the key, rather than somewhere inside
 * signing.
 */
final readonly class SignetConfigFactory
{
    public function __construct(private Config $config) {}

    public function make(): SignetConfig
    {
        return new SignetConfig(
            signing: $this->signing(),
            certificate: $this->certificate(),
            seal: $this->seal(),
            tempPath: $this->string('temp_path'),
        );
    }

    private function signing(): SigningConfig
    {
        return new SigningConfig(
            profile: SignatureProfile::from($this->stringOr('signature.profile', SignatureProfile::PadesBB->value)),
            digest: DigestAlgorithm::from($this->stringOr('signature.digest_algorithm', DigestAlgorithm::Sha256->value)),
            timestamp: new TimestampConfig(
                url: $this->string('signature.timestamp.url'),
                username: $this->string('signature.timestamp.username'),
                password: $this->string('signature.timestamp.password'),
                timeout: $this->int('signature.timestamp.timeout', 20),
                attempts: $this->int('signature.timestamp.attempts', 3),
                backoff: $this->int('signature.timestamp.backoff', 200),
            ),
            ltv: new LtvConfig(
                timeout: $this->int('signature.ltv.timeout', 10),
                attempts: $this->int('signature.ltv.attempts', 2),
                backoff: $this->int('signature.ltv.backoff', 100),
            ),
        );
    }

    private function certificate(): CertificateConfig
    {
        return new CertificateConfig(
            legacy: $this->bool('certificate.legacy'),
            usePathEnv: $this->bool('certificate.use_path_env'),
        );
    }

    private function seal(): SealConfig
    {
        $rows = $this->config->get('a1-pdf-sign.seal.text.rows');

        return new SealConfig(
            driver: ImageDriver::from($this->stringOr('seal.driver', ImageDriver::Gd->value)),
            fontPath: $this->string('seal.font.path'),
            fontSize: FontSize::from($this->stringOr('seal.font.size', FontSize::Large->value)),
            fontColor: $this->stringOr('seal.font.color', '#16A085'),
            background: $this->string('seal.background'),
            transparent: $this->bool('seal.transparent', true),
            textX: $this->int('seal.text.x', 160),
            textRows: self::rows($rows),
        );
    }

    /**
     * @return list<int>
     */
    private static function rows(mixed $rows): array
    {
        if (! is_array($rows)) {
            return [80, 150, 250];
        }

        $out = [];

        foreach ($rows as $row) {
            $out[] = is_numeric($row) ? (int) $row : 0;
        }

        return $out;
    }

    private function string(string $key): ?string
    {
        $value = $this->config->get('a1-pdf-sign.' . $key);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function stringOr(string $key, string $default): string
    {
        return $this->string($key) ?? $default;
    }

    private function int(string $key, int $default): int
    {
        $value = $this->config->get('a1-pdf-sign.' . $key);

        return is_numeric($value) ? (int) $value : $default;
    }

    private function bool(string $key, bool $default = false): bool
    {
        $value = $this->config->get('a1-pdf-sign.' . $key);

        return is_bool($value) ? $value : (is_scalar($value) ? (bool) $value : $default);
    }
}
