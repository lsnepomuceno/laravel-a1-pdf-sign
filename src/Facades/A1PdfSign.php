<?php

declare(strict_types=1);

namespace LSNepomuceno\LaravelA1PdfSign\Facades;

use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use LSNepomuceno\LaravelA1PdfSign\Contracts\A1PdfSign as A1PdfSignContract;
use LSNepomuceno\LaravelA1PdfSign\Testing\A1PdfSignFake;

/**
 * @method static \LSNepomuceno\LaravelA1PdfSign\Data\SignedPdf signFromFile(string $pfxPath, string $password, string $pdfPath, ?bool $usePathEnv = null)
 * @method static \LSNepomuceno\LaravelA1PdfSign\Data\SignedPdf signFromPem(string $pemPath, string $password, string $pdfPath, ?string $privateKeyPath = null)
 * @method static \LSNepomuceno\LaravelA1PdfSign\Data\SignedPdf signFromUpload(\Illuminate\Http\UploadedFile $uploadedPfx, string $password, string $pdfPath, ?bool $usePathEnv = null)
 * @method static \LSNepomuceno\LaravelA1PdfSign\Data\EncryptedCertificate encryptCertificate(\Illuminate\Http\UploadedFile|string $uploadedOrPfxPath, string $password, ?bool $usePathEnv = null)
 * @method static \LSNepomuceno\LaravelA1PdfSign\Data\Certificate decryptCertificate(string $hashKey, string $encryptedCertificate, string $password, bool $isBase64 = false, ?bool $usePathEnv = null)
 * @method static \LSNepomuceno\LaravelA1PdfSign\Data\SignatureReport validate(string $pdfPath, ?\LSNepomuceno\LaravelA1PdfSign\Validation\TrustStore $trust = null)
 * @method static list<\LSNepomuceno\LaravelA1PdfSign\Data\SignatureField> signatureFields(string $pdfPath)
 * @method static \LSNepomuceno\LaravelA1PdfSign\Data\SignedPdf extendArchive(string $pdfPath)
 * @method static \LSNepomuceno\LaravelA1PdfSign\Data\IcpBrasilReport icpBrasil(string $pfxPath, string $password = '')
 * @method static \LSNepomuceno\LaravelA1PdfSign\Signing\PendingSignature newSignature()
 * @method static string tempPath(bool $tempFile = false, string $fileExt = '.pfx')
 *
 * @see \LSNepomuceno\LaravelA1PdfSign\A1PdfSignManager
 */
final class A1PdfSign extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return A1PdfSignContract::class;
    }

    /**
     * Signs nothing, and records what would have been signed.
     *
     * For a consuming application testing its own signing flow, so it needs no
     * PKCS#12 bundle in its repository and builds no CMS for a test that only
     * happens to touch the code path.
     *
     * ```php
     * $signing = A1PdfSign::fake();
     *
     * // … the application runs …
     *
     * $signing->assertSigned();
     * $signing->assertSignedWithProfile(SignatureProfile::PadesBLT);
     * ```
     *
     * It replaces `Contracts\PdfSigner` rather than this facade's own binding,
     * because the builder is the documented way in and it depends on that
     * contract: faking only the facade would leave
     * `newSignature()->…->sign()` reaching the real signer.
     */
    public static function fake(): A1PdfSignFake
    {
        // The facade's application is the container, and the accessor returns
        // it typed as the interface, which install() narrows.
        return A1PdfSignFake::install(Container::getInstance());
    }
}
