<?php

namespace LSNepomuceno\LaravelA1PdfSign\Enums;

/**
 * How a signed document is handed back to the caller.
 *
 * This enum disappears in PR 7: SignedPdf lets the caller pick the transport
 * after signing, so the signer stops deciding it. See ARCHITECTURE-V2.md §2.
 */
enum SignatureMode: string
{
    case Resource = 'resource';
    case Download = 'download';

    /**
     * Accepts an instance or its backing value; null when unrecognised.
     */
    public static function resolve(self|string $value): ?self
    {
        return $value instanceof self ? $value : self::tryFrom($value);
    }
}
