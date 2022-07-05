<?php

namespace LSNepomuceno\LaravelA1PdfSign\Entities;

class EncryptedCertificate extends BaseEntity
{
    public function __construct(
        public string $certificate,
        public string $password,
        public string $hash
    )
    {
    }
}
