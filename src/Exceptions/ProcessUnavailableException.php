<?php

declare(strict_types=1);

namespace LSNepomuceno\LaravelA1PdfSign\Exceptions;

use Exception;

/**
 * PHP cannot spawn a child process at all.
 *
 * `proc_open` is routinely listed in `disable_functions` on shared hosting, and
 * is absent or restricted on some serverless runtimes. Validation needs it,
 * because the CMS verdict comes from the `openssl` binary
 * (docs/decisions/0001-openssl-native-with-cli-fallback.md).
 *
 * It is raised rather than absorbed because the alternative was worse than an
 * error: `Validation\SignatureVerifier` caught everything and returned false,
 * so a perfectly valid signature was reported as **invalid**, with no exception
 * and nothing in a log. A caller cannot tell that apart from a tampered
 * document, and the natural response is to reject something legitimate.
 */
final class ProcessUnavailableException extends Exception
{
    public function __construct(string $function = 'proc_open')
    {
        parent::__construct(
            "PHP cannot start a child process: {$function}() is unavailable, which usually means it is "
            . 'listed in disable_functions. Signature validation needs the openssl binary, so it cannot '
            . 'run in this environment.',
        );
    }
}
