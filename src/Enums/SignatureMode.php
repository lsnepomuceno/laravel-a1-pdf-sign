<?php

namespace LSNepomuceno\LaravelA1PdfSign\Enums;

/**
 * How a signed document is handed back to the caller.
 *
 * The backing values match the string constants this replaces
 * (SignaturePdf::MODE_*).
 *
 * This enum disappears in PR 7: SignedPdf lets the caller pick the transport
 * after signing, so the signer stops deciding it. See ARCHITECTURE-V2.md §2.
 */
enum SignatureMode: string
{
    case Resource = 'MODE_RESOURCE';
    case Download = 'MODE_DOWNLOAD';

    /**
     * Accepts either an instance or one of the legacy string constants.
     */
    public static function resolve(self|string $value): ?self
    {
        return $value instanceof self ? $value : self::tryFrom($value);
    }
}
