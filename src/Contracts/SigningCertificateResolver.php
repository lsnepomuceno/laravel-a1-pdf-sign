<?php

declare(strict_types=1);

namespace LSNepomuceno\LaravelA1PdfSign\Contracts;

use LSNepomuceno\Signet\Data\Certificate;

/**
 * Which certificate an agent signs with, answered by the application.
 *
 * **This is what keeps the certificate and its password out of the model.**
 * `Ai\Tools\SignPdf` takes a document, a destination, a profile and a reason
 * from the agent, and nothing else. The key that signs is whatever this
 * returns, which is normally the authenticated user's own certificate, opened
 * from wherever the application keeps it:
 *
 * ```php
 * final readonly class UserCertificate implements SigningCertificateResolver
 * {
 *     public function resolve(): Certificate
 *     {
 *         $stored = auth()->user()->certificate;
 *
 *         return A1PdfSign::decryptCertificate($stored->hash, $stored->certificate, $stored->password);
 *     }
 * }
 * ```
 *
 * It is called twice per signature: once to describe the certificate in the
 * approval the human is asked for, and once to sign after they approve. Both
 * happen in the request that carries the agent, so `auth()` answers in both
 * (docs/decisions/0040-agents-read-through-mcp-and-sign-through-the-ai-sdk.md).
 */
interface SigningCertificateResolver
{
    /**
     * @throws \Throwable When the certificate cannot be produced. The tool
     *                    reports it rather than signing with anything else.
     */
    public function resolve(): Certificate;
}
