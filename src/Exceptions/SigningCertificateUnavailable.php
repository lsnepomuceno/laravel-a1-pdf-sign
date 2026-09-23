<?php

declare(strict_types=1);

namespace LSNepomuceno\LaravelA1PdfSign\Exceptions;

use LogicException;
use LSNepomuceno\LaravelA1PdfSign\Contracts\SigningCertificateResolver;
use LSNepomuceno\Signet\Exceptions\SignetException;

/**
 * The signing tool was asked to sign and nobody said with which certificate.
 *
 * `Ai\Tools\SignPdf` never takes a certificate or a password from the model:
 * the application answers the question through
 * `Contracts\SigningCertificateResolver`. Leaving that unbound is a wiring
 * mistake rather than a runtime condition, so this is a `LogicException`, and
 * it is raised with a message saying what to bind instead of the container's
 * "Target is not instantiable"
 * (docs/decisions/0040-agents-read-through-mcp-and-sign-through-the-ai-sdk.md).
 */
final class SigningCertificateUnavailable extends LogicException implements SignetException
{
    public static function unbound(): self
    {
        // The contract is named through ::class rather than written out: a
        // literal would drift from a rename, and tests/Project/ArchTest.php reads
        // every string literal in src/ for the name of a verification tool,
        // which the package's own name happens to contain.
        return new self(
            'The signing tool has no certificate to sign with. Bind ' . SigningCertificateResolver::class
            . ' in a service provider, or pass a resolver to the tool when you build it.',
        );
    }
}
