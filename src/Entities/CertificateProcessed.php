<?php

namespace LSNepomuceno\LaravelA1PdfSign\Entities;

class CertificateProcessed extends BaseEntity
{
    public function __construct(
        public string                   $original,
        public \OpenSSLCertificate|bool $openssl,
        public array                    $data,
        public string                   $password
    )
    {
    }

}
