<?php

declare(strict_types=1);

namespace LSNepomuceno\LaravelA1PdfSign\Testing;

use LSNepomuceno\LaravelA1PdfSign\Contracts\CertificateReader;
use LSNepomuceno\LaravelA1PdfSign\Data\Certificate;

/**
 * Reads no certificate, and answers as though it had.
 *
 * Installed by `A1PdfSign::fake()` so a consuming application can exercise
 * `certificate($path, $password)` without a PKCS#12 bundle in its repository.
 * It reads nothing from disk and generates no key, which is the difference
 * between this and `DebugCertificate`.
 */
final readonly class FakeCertificateReader implements CertificateReader
{
    #[\Override]
    public function read(
        string $contents,
        #[\SensitiveParameter]
        string $password,
    ): Certificate {
        return A1PdfSignFake::certificate();
    }
}
