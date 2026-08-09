<?php

namespace LSNepomuceno\LaravelA1PdfSign\Contracts;

use LSNepomuceno\LaravelA1PdfSign\Data\Certificate;
use SensitiveParameter;

/**
 * Turns encoded certificate bytes into a parsed certificate.
 *
 * Implementations differ only in the encoding they ingest: PKCS#12, whether
 * read natively or through the CLI, and PEM, which needs no conversion at all.
 * All of them converge on the same PEM bundle and the same
 * {@see \LSNepomuceno\LaravelA1PdfSign\Certificates\CertificateParser}.
 */
interface CertificateReader
{
    /**
     * @param  string  $contents  The raw bytes of a certificate bundle, in the encoding
     *                            the implementation reads.
     *
     * @throws \LSNepomuceno\LaravelA1PdfSign\Exceptions\CertificateOutputNotFoundException
     * @throws \LSNepomuceno\LaravelA1PdfSign\Exceptions\InvalidCertificateContentException
     * @throws \LSNepomuceno\LaravelA1PdfSign\Exceptions\InvalidPemContentException
     * @throws \LSNepomuceno\LaravelA1PdfSign\Exceptions\InvalidX509PrivateKeyException
     * @throws \LSNepomuceno\LaravelA1PdfSign\Exceptions\ProcessRunTimeException
     */
    public function read(
        string $contents,
        #[SensitiveParameter]
        string $password,
    ): Certificate;
}
