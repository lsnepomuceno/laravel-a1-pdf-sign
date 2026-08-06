<?php

namespace LSNepomuceno\LaravelA1PdfSign\Facades;

use Illuminate\Support\Facades\Facade;
use LSNepomuceno\LaravelA1PdfSign\Contracts\A1PdfSign as A1PdfSignContract;

/**
 * @method static \Symfony\Component\HttpFoundation\BinaryFileResponse|string signFromFile(string $pfxPath, string $password, string $pdfPath, \LSNepomuceno\LaravelA1PdfSign\Enums\SignatureMode|string|null $mode = null, ?bool $usePathEnv = null)
 * @method static \Symfony\Component\HttpFoundation\BinaryFileResponse|string signFromUpload(\Illuminate\Http\UploadedFile $uploadedPfx, string $password, string $pdfPath, \LSNepomuceno\LaravelA1PdfSign\Enums\SignatureMode|string|null $mode = null, ?bool $usePathEnv = null)
 * @method static \LSNepomuceno\LaravelA1PdfSign\Data\EncryptedCertificate encryptCertificate(\Illuminate\Http\UploadedFile|string $uploadedOrPfxPath, string $password, ?bool $usePathEnv = null)
 * @method static \LSNepomuceno\LaravelA1PdfSign\Sign\ManageCert decryptCertificate(string $hashKey, string $encryptedCertificate, string $password, bool $isBase64 = false, ?bool $usePathEnv = null)
 * @method static \LSNepomuceno\LaravelA1PdfSign\Data\SignatureReport validate(string $pdfPath)
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
