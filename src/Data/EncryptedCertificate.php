<?php

namespace LSNepomuceno\LaravelA1PdfSign\Data;

use SensitiveParameter;

/**
 * A certificate and its password, encrypted for storage.
 *
 * The hash is the key both values were encrypted with, and is required to
 * decrypt them again.
 */
readonly class EncryptedCertificate extends BaseData
{
    public function __construct(
        public string $certificate,
        #[SensitiveParameter]
        public string $password,
        #[SensitiveParameter]
        public string $hash,
    ) {}
}
