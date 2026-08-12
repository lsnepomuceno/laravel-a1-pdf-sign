<?php

namespace LSNepomuceno\LaravelA1PdfSign\Testing;

use LSNepomuceno\LaravelA1PdfSign\Contracts\SignatureTransport;
use LSNepomuceno\LaravelA1PdfSign\Support\ProcessRunner;

/**
 * The local timestamp authority, with revocation material to hand out.
 *
 * `LocalTimestampAuthority` answers false for OCSP and CRL, which is honest for
 * a self-signed certificate: it has no responder and no distribution point, so
 * a document signed with one carries no revocation material and the live tests
 * produce exactly the same. That leaves the code that gathers a Document
 * Security Store unexercised offline.
 *
 * This serves whatever it was given, at whatever URL it is asked for. **The
 * material is not evaluated here**, and that separation is deliberate: whether
 * a response really covers a certificate is
 * `Validation\RevocationChecker`'s question, with its own tests and its own
 * fixtures ([0024](../../docs/decisions/0024-revocation-is-evaluated-not-counted.md)).
 * What this makes testable is that the store is written, refreshed and covered
 * by the timestamp that follows it.
 *
 * Test-only, and kept out of the production classes exactly as its parent is.
 *
 * See docs/decisions/0022-the-archive-timestamp-is-a-chain.md.
 */
final class LocalRevocationAuthority implements SignatureTransport
{
    private readonly LocalTimestampAuthority $timestamps;

    /**
     * @param  ?string  $crl  DER, or null to answer as though there were none.
     * @param  ?string  $ocsp  DER, or null.
     */
    public function __construct(
        ProcessRunner $processes,
        private readonly ?string $crl = null,
        private readonly ?string $ocsp = null,
    ) {
        $this->timestamps = new LocalTimestampAuthority($processes);
    }

    /**
     * @return callable(string): string
     */
    public function timestamp(string $url, ?string $username = null, ?string $password = null): callable
    {
        return $this->timestamps->timestamp($url, $username, $password);
    }

    /**
     * @return callable(string, string): (string|false)
     */
    public function ocsp(): callable
    {
        $response = $this->ocsp;

        return static fn(string $url, string $request): string|false => $response ?? false;
    }

    /**
     * @return callable(string): (string|false)
     */
    public function crl(): callable
    {
        $list = $this->crl;

        return static fn(string $url): string|false => $list ?? false;
    }
}
