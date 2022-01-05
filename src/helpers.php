<?php

use LSNepomuceno\LaravelA1PdfSign\{ManageCert, SignaturePdf, ValidatePdfSignature};
use Illuminate\Support\{Str, Facades\File, Fluent};
use Illuminate\Http\UploadedFile;

if (!function_exists('signPdf')) {
    /**
     * signPdf - Helper to fast signature pdf from pfx file
     *
     * @param string $pfxPath
     * @param string $password
     * @param string $pdfPath
     * @param string $mode
     * @return mixed
     * @throws Throwable
     */
    function signPdfFromFile(string $pfxPath, string $password, string $pdfPath, string $mode = SignaturePdf::MODE_RESOURCE)
    {
        try {
            return (new SignaturePdf(
                $pdfPath,
                (new ManageCert)->fromPfx($pfxPath, $password),
                $mode
            ))->signature();
        } catch (\Throwable $th) {
            throw $th;
        }
    }
}

if (!function_exists('signPdfFromUpload')) {
    /**
     * signPdfFromUpload - Helper to fast signature pdf from uploaded certificate
     *
     * @param UploadedFile $uploadedPfx
     * @param string $password
     * @param string $pdfPath
     * @param string $mode
     * @return mixed
     * @throws Throwable
     */
    function signPdfFromUpload(UploadedFile $uploadedPfx, string $password, string $pdfPath, string $mode = SignaturePdf::MODE_RESOURCE)
    {
        try {
            return (new SignaturePdf(
                $pdfPath,
                (new ManageCert)->fromUpload($uploadedPfx, $password),
                $mode
            ))->signature();
        } catch (\Throwable $th) {
            throw $th;
        }
    }
}

if (!function_exists('encryptCertData')) {
    /**
     * encryptCertData - Helper to fast encrypt certificate data
     *
     * @param UploadedFile|string $uploadedOrPfxPath
     * @param string $password
     * @return Fluent
     * @throws Throwable
     */
    function encryptCertData($uploadedOrPfxPath, string $password): Fluent
    {
        try {
            $cert = new ManageCert;

            if ($uploadedOrPfxPath instanceof UploadedFile) {
                $cert->fromUpload($uploadedOrPfxPath, $password);
            } else {
                $cert->fromPfx($uploadedOrPfxPath, $password);
            }

            return new Fluent([
                'certificate' => $cert->getEncrypter()->encryptString($cert->getCert()->original),
                'password' => $cert->getEncrypter()->encryptString($password),
                'hash' => $cert->getHashKey(), // IMPORTANT, USE ON DECRYPT HELPER
            ]);
        } catch (\Throwable $th) {
            throw $th;
        }
    }
}

if (!function_exists('decryptCertData')) {
    /**
     * decryptCertData - Helper to fast decrypt certificate
     *
     * @param string $hashKey
     * @param string $encryptCert
     * @param string $password
     * @param bool $isBase64
     * @return ManageCert
     * @throws Throwable
     */
    function decryptCertData(string $hashKey, string $encryptCert, string $password, bool $isBase64 = false): ManageCert
    {
        try {
            $cert = (new ManageCert)->setHashKey($hashKey);
            $uuid = Str::orderedUuid();
            $pfxName = "{$cert->getTempDir()}{$uuid}.pfx";

            $decryptedData = $cert->getEncrypter()->decryptString($encryptCert);
            File::put($pfxName, $isBase64 ? base64_decode($decryptedData) : $decryptedData);

            return $cert->fromPfx(
                $pfxName,
                $cert->getEncrypter()->decryptString($password)
            );
        } catch (\Throwable $th) {
            throw $th;
        }
    }
}

if (!function_exists('a1TempDir')) {
    /**
     * a1TempDir - Helper to make temp dir and files
     *
     * @param bool $tempFile
     * @param string $fileExt
     * @return string
     */
    function a1TempDir(bool $tempFile = false, string $fileExt = '.pfx'): string
    {
        $tempDir = __DIR__ . '/Temp/';

        if ($tempFile) $tempDir .= Str::orderedUuid() . $fileExt;

        return $tempDir;
    }
}

if (!function_exists('validatePdfSignature')) {
    /**
     * validatePdfSignature - Validate pdf signature
     *
     * @param string $pdfPath
     * @return Fluent
     * @throws Throwable
     */
    function validatePdfSignature(string $pdfPath): Fluent
    {
        try {
            return ValidatePdfSignature::from($pdfPath);
        } catch (\Throwable $th) {
            throw $th;
        }
    }
}
