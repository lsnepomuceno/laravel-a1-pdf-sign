<?php

namespace LSNepomuceno\LaravelA1PdfSign\Validation;

use LSNepomuceno\LaravelA1PdfSign\Contracts\A1PdfSign;
use LSNepomuceno\LaravelA1PdfSign\Support\ProcessRunner;
use LSNepomuceno\LaravelA1PdfSign\Support\TemporaryFile;
use Throwable;

/**
 * Decides whether a detached CMS matches the bytes it covers.
 *
 * This is the one part of validation that shells out, and deliberately so.
 * PHP's openssl_pkcs7_verify() cannot take detached content — it only writes
 * the verified content out — and reconstructing an S/MIME envelope around
 * binary PDF bytes fails on MIME canonicalisation. The alternative is walking
 * the CMS grammar by hand to check the message-digest attribute and verify the
 * signed attributes, which is exactly the kind of code whose bugs produce a
 * false "valid".
 *
 * For a security decision, deferring to OpenSSL's own implementation is the
 * conservative choice. The call is confined to the audited ProcessRunner, and
 * -purpose any with no CA file means this answers "does the signature match
 * the content", not "is the issuer trusted".
 */
final readonly class SignatureVerifier
{
    public function __construct(
        private ProcessRunner $processes,
        private A1PdfSign $paths,
    ) {}

    public function verify(string $cms, string $coveredBytes): bool
    {
        $directory = $this->paths->tempPath();

        try {
            return TemporaryFile::with($directory, '.p7s', $cms, function (TemporaryFile $signature) use ($directory, $coveredBytes): bool {
                return TemporaryFile::with($directory, '.bin', $coveredBytes, function (TemporaryFile $content) use ($signature): bool {
                    $this->processes->run(sprintf(
                        'openssl smime -verify -binary -inform DER -in %s -content %s -noverify -purpose any -out /dev/null 2>&1',
                        escapeshellarg($signature->path),
                        escapeshellarg($content->path),
                    ));

                    return true;
                });
            });
        } catch (Throwable) {
            // A non-zero exit is the expected outcome for a signature that does
            // not match; it is a verdict, not a failure to report.
            return false;
        }
    }
}
