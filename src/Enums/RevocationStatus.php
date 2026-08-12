<?php

declare(strict_types=1);

namespace LSNepomuceno\LaravelA1PdfSign\Enums;

/**
 * What the document's own revocation material says about a signer.
 *
 * Three answers, not two, for the same reason trust has three
 * ([0016](../../docs/decisions/0016-trust-is-the-applications-policy.md)):
 * "nothing in this document says" is not "this certificate is fine".
 */
enum RevocationStatus: string
{
    /** A verified OCSP response or CRL covers this certificate and does not revoke it. */
    case Good = 'good';

    /** A verified source says it was revoked. */
    case Revoked = 'revoked';

    /**
     * Nobody asked, nothing was carried, nothing matched, or what was carried
     * could not be verified against the issuer.
     */
    case Unknown = 'unknown';

    /**
     * Whether this is an answer at all.
     */
    public function isKnown(): bool
    {
        return $this !== self::Unknown;
    }
}
