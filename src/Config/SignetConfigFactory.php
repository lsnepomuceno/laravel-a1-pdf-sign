<?php

declare(strict_types=1);

namespace LSNepomuceno\LaravelA1PdfSign\Config;

use Illuminate\Contracts\Config\Repository as Config;
use InvalidArgumentException;
use LSNepomuceno\Signet\Config\{CertificateConfig, LtvConfig, SealConfig, SignetConfig, SigningConfig, TimestampConfig};
use LSNepomuceno\Signet\Data\SignaturePolicy;
use LSNepomuceno\Signet\Enums\{DigestAlgorithm, FontSize, ImageDriver, SignatureProfile};
use LSNepomuceno\Signet\IcpBrasil\Enums\SignaturePolicy as IcpBrasilPolicy;
use ValueError;

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
            profile: $this->enum(SignatureProfile::class, 'signature.profile', SignatureProfile::PadesBB->value),
            digest: $this->enum(DigestAlgorithm::class, 'signature.digest_algorithm', DigestAlgorithm::Sha256->value),
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
            policy: $this->policy(),
        );
    }

    /**
     * The signature policy a document declares it was made under.
     *
     * Named by its ICP-Brasil identifier, `ad-rb`, `ad-rt`, `ad-rc` or
     * `ad-ra`, which resolves to whichever version of that policy is current.
     * A caller who needs a specific version names the OID instead, and one who
     * needs a policy from another country supplies the three fields.
     *
     * Null, the default, declares none, which is what every signature this
     * package produced before 3.0.
     */
    private function policy(): ?SignaturePolicy
    {
        $policy = $this->config->get('a1-pdf-sign.signature.policy');

        if ($policy === null || $policy === '' || $policy === []) {
            return null;
        }

        if (is_string($policy)) {
            return self::named($policy)->identifier();
        }

        if (! is_array($policy)) {
            throw new InvalidArgumentException(
                'a1-pdf-sign.signature.policy must be a policy name, an OID, or the three fields of one',
            );
        }

        $oid = $policy['oid'] ?? null;
        $digest = $policy['digest'] ?? null;
        $algorithm = $policy['digest_algorithm'] ?? null;
        $uri = $policy['uri'] ?? null;

        if (! is_string($oid) || ! is_string($digest) || ! is_string($algorithm)) {
            throw new InvalidArgumentException(
                'a1-pdf-sign.signature.policy needs oid, digest and digest_algorithm when it is given as an array',
            );
        }

        return new SignaturePolicy($oid, $algorithm, $digest, is_string($uri) ? $uri : null);
    }

    /**
     * A policy by short name or by OID.
     *
     * The short names resolve to the current version rather than to a fixed
     * one: a policy is superseded on a date, and an application naming `ad-rt`
     * means the one in force, not the one in force when the config file was
     * written.
     */
    private static function named(string $policy): IcpBrasilPolicy
    {
        $profile = SignatureProfile::tryFrom(match (strtolower($policy)) {
            'ad-rb' => SignatureProfile::PadesBB->value,
            'ad-rt' => SignatureProfile::PadesBT->value,
            'ad-rc' => SignatureProfile::PadesBLT->value,
            'ad-ra' => SignatureProfile::PadesBLTA->value,
            default => '',
        });

        $current = $profile === null ? null : IcpBrasilPolicy::forProfile($profile);

        if ($current !== null) {
            return $current;
        }

        $byOid = IcpBrasilPolicy::tryFrom($policy);

        if ($byOid === null) {
            throw new InvalidArgumentException(
                "a1-pdf-sign.signature.policy does not name a known policy: [{$policy}]",
            );
        }

        return $byOid;
    }

    /**
     * A backed enum from a config value, naming the key when it is wrong.
     *
     * The cast happens once, here, so a typo fails when the container boots
     * and says which key carries it. Left to the engine it would surface from
     * inside signing, where the key that produced it is no longer visible.
     *
     * @template T of \BackedEnum
     *
     * @param  class-string<T>  $enum
     * @return T
     */
    private function enum(string $enum, string $key, string $default): \BackedEnum
    {
        $value = $this->stringOr($key, $default);

        try {
            return $enum::from($value);
        } catch (ValueError) {
            $allowed = implode(', ', array_map(static fn(\BackedEnum $case): string => (string) $case->value, $enum::cases()));

            throw new InvalidArgumentException("a1-pdf-sign.{$key} is [{$value}], and has to be one of: {$allowed}");
        }
    }

    private function certificate(): CertificateConfig
    {
        $chain = $this->config->get('a1-pdf-sign.certificate.chain_paths');

        return new CertificateConfig(
            legacy: $this->bool('certificate.legacy'),
            usePathEnv: $this->bool('certificate.use_path_env'),
            // The intermediates to embed when the bundle carries none. The
            // engine builds the chain rather than trusting the order these
            // arrive in, so the list is a set of files and not a sequence.
            chainPaths: is_array($chain) ? array_values(array_filter($chain, is_string(...))) : [],
        );
    }

    private function seal(): SealConfig
    {
        $rows = $this->config->get('a1-pdf-sign.seal.text.rows');

        return new SealConfig(
            driver: $this->enum(ImageDriver::class, 'seal.driver', ImageDriver::Gd->value),
            fontPath: $this->string('seal.font.path'),
            fontSize: $this->enum(FontSize::class, 'seal.font.size', FontSize::Large->value),
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
