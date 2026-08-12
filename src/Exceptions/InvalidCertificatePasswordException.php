<?php

declare(strict_types=1);

namespace LSNepomuceno\LaravelA1PdfSign\Exceptions;

use Exception;

/**
 * The bundle is fine and the password is not.
 *
 * The most common failure a consuming application meets in production, and it
 * had no class of its own: it arrived as InvalidCertificateContentException
 * with the reason in the message, so telling "wrong password" from "corrupt
 * bundle" meant reading a string, which is the thing typed exceptions exist to
 * avoid.
 *
 * **It extends the class it used to be**, so every existing catch keeps
 * matching and this is additive rather than breaking.
 *
 * The distinction is evidence, not a guess. OpenSSL reports the two cases
 * differently, measured on this package's own throwaway bundle:
 *
 * | wrong password | `error:11800071:PKCS12 routines::mac verify failure` |
 * | corrupt bundle | `error:0680008E:asn1 encoding routines::not enough data` |
 *
 * The MAC is computed over the bundle with a key derived from the password, so
 * a MAC that does not verify is precisely the statement "this password did not
 * open this file". Anything else stays the general exception.
 */
final class InvalidCertificatePasswordException extends InvalidCertificateContentException
{
    public function __construct(int $code = 0, ?Exception $previous = null)
    {
        parent::__construct(
            'the password did not open the bundle: OpenSSL reports a MAC verify failure, which means the '
            . 'file is intact and the password is wrong.',
            $code,
            $previous,
        );
    }
}
