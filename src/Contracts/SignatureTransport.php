<?php

namespace LSNepomuceno\LaravelA1PdfSign\Contracts;

/**
 * The network a signature needs: the timestamp authority, OCSP and CRL.
 *
 * tc-lib-pdf-sign keeps its codecs pure and takes transports as callables, so
 * the host owns networking and therefore owns the SSRF surface. Invariant 9
 * says nothing else in `src/` opens a connection, and this is the seam that
 * makes the rule true.
 *
 * **It is an interface because injection you cannot substitute is not
 * injection.** Everything the profiles above `pades-b-b` add rides through
 * here, so a suite that cannot replace it can only test them against a live
 * authority: reported, never blocking, and dependent on somebody else's uptime.
 * `Testing\LocalTimestampAuthority` is the substitute, and it turns the
 * timestamp surface into a gate.
 *
 * See docs/decisions/0027-the-transport-is-a-seam.md.
 */
interface SignatureTransport
{
    /**
     * Posts a DER TimeStampReq and returns the DER TimeStampResp.
     *
     * @return callable(string): string
     */
    public function timestamp(string $url, ?string $username = null, ?string $password = null): callable;

    /**
     * Posts a DER OCSP request and returns the DER response, or false to skip.
     *
     * False rather than an exception: a responder being unreachable degrades
     * the profile and must not fail the signature.
     *
     * @return callable(string, string): (string|false)
     */
    public function ocsp(): callable;

    /**
     * Fetches a CRL, or false to skip it.
     *
     * @return callable(string): (string|false)
     */
    public function crl(): callable;
}
