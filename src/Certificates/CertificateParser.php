<?php

namespace LSNepomuceno\LaravelA1PdfSign\Certificates;

use LSNepomuceno\LaravelA1PdfSign\Data\Certificate;
use LSNepomuceno\LaravelA1PdfSign\Exceptions\InvalidCertificateContentException;
use LSNepomuceno\LaravelA1PdfSign\Exceptions\InvalidX509PrivateKeyException;
use SensitiveParameter;

/**
 * Parses and validates a PEM bundle, whichever reader produced it.
 *
 * Both readers converge here, so "is this certificate usable" is answered in
 * one place rather than once per driver.
 */
final class CertificateParser
{
    /**
     * @throws InvalidCertificateContentException
     * @throws InvalidX509PrivateKeyException
     */
    public function parse(
        string $pem,
        #[SensitiveParameter]
        string $password = '',
    ): Certificate {
        $x509 = openssl_x509_read($pem);

        if ($x509 === false) {
            throw new InvalidCertificateContentException();
        }

        if (! openssl_x509_check_private_key($x509, $pem)) {
            throw new InvalidX509PrivateKeyException();
        }

        return new Certificate(
            original: $pem,
            openssl: $x509,
            data: openssl_x509_parse($x509, false) ?: [],
            password: $password,
        );
    }
}
