<?php

namespace LSNepomuceno\LaravelA1PdfSign\Enums;

use Com\Tecnick\Pdf\Sign\Config as CadesConfig;

/**
 * The signature profile, in ascending order of guarantee.
 *
 * Each level builds on the previous one. Anything above Legacy is a PAdES
 * baseline signature and carries the ESS signing-certificate-v2 attribute,
 * which openssl_pkcs7_sign() cannot produce.
 *
 * The values mirror the upstream constants so the mapping stays a lookup, but
 * this enum is the package's own type: the vendor's is not part of our API.
 */
enum SignatureProfile: string
{
    /** ISO 32000-1 detached CMS. Widest reader support, weakest guarantee. */
    case Legacy = 'legacy';

    /** CAdES signed attributes. The PAdES baseline. */
    case PadesBB = 'pades-b-b';

    /** B-B plus an RFC 3161 timestamp, so the signing time is attested. */
    case PadesBT = 'pades-b-t';

    /** B-T plus a Document Security Store, so it validates after the certificate expires. */
    case PadesBLT = 'pades-b-lt';

    /** B-LT plus an archive timestamp, for long-term preservation. */
    case PadesBLTA = 'pades-b-lta';

    public function isPades(): bool
    {
        return $this !== self::Legacy;
    }

    /**
     * Whether the profile needs an RFC 3161 timestamp authority.
     */
    public function needsTimestamp(): bool
    {
        return in_array($this, [self::PadesBT, self::PadesBLT, self::PadesBLTA], true);
    }

    /**
     * Whether the profile embeds revocation material in a Document Security Store.
     */
    public function needsValidationMaterial(): bool
    {
        return in_array($this, [self::PadesBLT, self::PadesBLTA], true);
    }

    public function subFilter(): string
    {
        return $this->isPades() ? 'ETSI.CAdES.detached' : 'adbe.pkcs7.detached';
    }

    public function toCadesConfig(string $digestAlgorithm = 'sha256'): CadesConfig
    {
        return new CadesConfig($this->value, $digestAlgorithm);
    }

    public static function resolve(self|string|null $value): self
    {
        if ($value instanceof self) {
            return $value;
        }

        return $value === null ? self::PadesBB : (self::tryFrom($value) ?? self::PadesBB);
    }
}
