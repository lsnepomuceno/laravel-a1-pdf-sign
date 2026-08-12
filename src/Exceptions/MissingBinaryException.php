<?php

declare(strict_types=1);

namespace LSNepomuceno\LaravelA1PdfSign\Exceptions;

use Exception;

/**
 * A binary the package needs is not on the PATH.
 *
 * In practice this is `openssl`, and the distinction that matters is that
 * `ext-openssl` being loaded says nothing about the binary being installed:
 * they are different things, and a minimal container commonly has the first
 * without the second.
 *
 * Raised rather than absorbed for the reason in ProcessUnavailableException:
 * absorbing it made a valid signature report as invalid.
 */
final class MissingBinaryException extends Exception implements A1PdfSignException
{
    public function __construct(public readonly string $binary)
    {
        parent::__construct(
            "The '{$binary}' binary was not found on the PATH. Signature validation shells out to it, so "
            . 'it has to be installed even where ext-openssl is already loaded: the extension and the '
            . 'command-line tool are separate things.',
        );
    }
}
