<?php

namespace LSNepomuceno\LaravelA1PdfSign\Facades;

use Illuminate\Support\Facades\Facade;
use LSNepomuceno\LaravelA1PdfSign\Contracts\A1PdfSign as A1PdfSignContract;

/**
 * @method static \LSNepomuceno\LaravelA1PdfSign\Data\SignedPdf signFromFile(string $pfxPath, string $password, string $pdfPath, ?bool $usePathEnv = null)
 * @method static \LSNepomuceno\LaravelA1PdfSign\Data\SignedPdf signFromPem(string $pemPath, string $password, string $pdfPath, ?string $privateKeyPath = null)
 * @method static \LSNepomuceno\LaravelA1PdfSign\Data\SignedPdf signFromUpload(\Illuminate\Http\UploadedFile $uploadedPfx, string $password, string $pdfPath, ?bool $usePathEnv = null)
 * @method static \LSNepomuceno\LaravelA1PdfSign\Data\EncryptedCertificate encryptCertificate(\Illuminate\Http\UploadedFile|string $uploadedOrPfxPath, string $password, ?bool $usePathEnv = null)
 * @method static \LSNepomuceno\LaravelA1PdfSign\Data\Certificate decryptCertificate(string $hashKey, string $encryptedCertificate, string $password, bool $isBase64 = false, ?bool $usePathEnv = null)
 * @method static \LSNepomuceno\LaravelA1PdfSign\Data\SignatureReport validate(string $pdfPath, ?\LSNepomuceno\LaravelA1PdfSign\Validation\TrustStore $trust = null)
 * @method static list<\LSNepomuceno\LaravelA1PdfSign\Data\SignatureField> signatureFields(string $pdfPath)
 * @method static \LSNepomuceno\LaravelA1PdfSign\Data\SignedPdf extendArchive(string $pdfPath)
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
}
