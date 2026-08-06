<?php

namespace LSNepomuceno\LaravelA1PdfSign\Contracts;

use LSNepomuceno\LaravelA1PdfSign\Data\Certificate;
use SensitiveParameter;

/**
 * Turns a PKCS#12 bundle into a parsed certificate.
 */
interface CertificateReader
{
    /**
     * @param  string  $pfxContents  The raw bytes of a .pfx / .p12 file.
     *
     * @throws \LSNepomuceno\LaravelA1PdfSign\Exceptions\CertificateOutputNotFoundException
     * @throws \LSNepomuceno\LaravelA1PdfSign\Exceptions\InvalidCertificateContentException
     * @throws \LSNepomuceno\LaravelA1PdfSign\Exceptions\InvalidX509PrivateKeyException
     * @throws \LSNepomuceno\LaravelA1PdfSign\Exceptions\ProcessRunTimeException
     */
    public function read(
        string $pfxContents,
        #[SensitiveParameter]
        string $password,
    ): Certificate;
}
