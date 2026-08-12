<?php

namespace LSNepomuceno\LaravelA1PdfSign\Validation;

use LSNepomuceno\LaravelA1PdfSign\Contracts\A1PdfSign;
use LSNepomuceno\LaravelA1PdfSign\Exceptions\ProcessRunTimeException;
use LSNepomuceno\LaravelA1PdfSign\Support\ProcessRunner;
use LSNepomuceno\LaravelA1PdfSign\Support\TemporaryFile;

/**
 * Decides whether a detached CMS matches the bytes it covers.
 *
 * This is the one part of validation that shells out, and deliberately so.
 * PHP's openssl_pkcs7_verify() cannot take detached content (it only writes
 * the verified content out) and reconstructing an S/MIME envelope around
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
        } catch (ProcessRunTimeException) {
            // A non-zero exit is the expected outcome for a signature that does
            // not match; it is a verdict, not a failure to report.
            //
            // Narrowed from catch (Throwable), which swallowed the difference
            // between "this signature does not verify" and "nothing verified
            // it". A missing openssl binary, proc_open disabled, an unwritable
            // temporary directory: every one of those used to arrive here and
            // leave as `false`, so a valid document was reported as invalid
            // with nothing to read anywhere. ProcessUnavailableException and
            // MissingBinaryException now travel past this catch on purpose.
            return false;
        }
    }

    /**
     * Decides whether a DocTimeStamp token really stamps the bytes it sits in.
     *
     * A timestamp token is not a detached CMS. Its eContent is a TSTInfo, and
     * the TSTInfo carries a messageImprint: the digest of what was stamped. So
     * two things have to hold, and checking only the first is worse than
     * checking neither, because a token lifted from another document passes it.
     *
     *   1. the token's own CMS verifies, which is what writing the TSTInfo out
     *      proves, and
     *   2. that TSTInfo's imprint is the digest of the range this token covers.
     *
     * The second is answered by looking for the digest inside the TSTInfo
     * rather than by walking the ASN.1 to the messageImprint field. The value
     * searched for is 32 or more bytes of a cryptographic digest computed here,
     * so a match cannot be coincidental: finding it means the TSA stamped these
     * bytes. Not finding it means it did not.
     *
     * See docs/decisions/0010-validation-consumes-what-signing-writes.md.
     */
    public function verifyTimestamp(string $token, string $coveredBytes): bool
    {
        return $this->verifiedTimestampInfo($token, $coveredBytes) !== null;
    }

    /**
     * The TSTInfo of a token that verifies and really stamps these bytes, or
     * null.
     *
     * Both halves of `verifyTimestamp()` and the structure itself, because the
     * answer "yes, and here is what the authority asserted" is strictly more
     * than "yes" and costs the same two processes. `genTime` lives in here, and
     * it is the only time in a signed document attributable to anyone other
     * than the signer.
     */
    public function verifiedTimestampInfo(string $token, string $stampedBytes): ?string
    {
        try {
            $tstInfo = $this->tstInfo($this->paths->tempPath(), $token);
        } catch (ProcessRunTimeException) {
            // A token whose own CMS does not verify writes nothing out.
            // Narrowed for the reason above: an environment that cannot run
            // openssl must not read as a token that failed to verify.
            return null;
        }

        return $tstInfo !== '' && $this->imprints($tstInfo, $stampedBytes) ? $tstInfo : null;
    }

    /**
     * The TSTInfo the token signs, as written out by OpenSSL.
     */
    private function tstInfo(string $directory, string $token): string
    {
        return TemporaryFile::with($directory, '.tst', $token, function (TemporaryFile $file) use ($directory): string {
            return TemporaryFile::with($directory, '.der', '', function (TemporaryFile $out) use ($file): string {
                $this->processes->run(sprintf(
                    'openssl smime -verify -binary -inform DER -in %s -noverify -purpose any -out %s 2>&1',
                    escapeshellarg($file->path),
                    escapeshellarg($out->path),
                ));

                return (string) file_get_contents($out->path);
            });
        });
    }

    /**
     * Whether the TSTInfo carries the digest of these bytes.
     *
     * Every algorithm a TSA might have used is tried, because the token names
     * its own choice and this does not depend on guessing it right.
     */
    private function imprints(string $tstInfo, string $coveredBytes): bool
    {
        foreach (['sha256', 'sha384', 'sha512', 'sha1'] as $algorithm) {
            if (str_contains($tstInfo, hash($algorithm, $coveredBytes, true))) {
                return true;
            }
        }

        return false;
    }
}
