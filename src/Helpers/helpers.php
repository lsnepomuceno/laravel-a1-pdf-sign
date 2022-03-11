<?php

use LSNepomuceno\LaravelA1PdfSign\Exceptions\ProcessRunTimeException;
use \LSNepomuceno\LaravelA1PdfSign\Sign\{ManageCert, SignaturePdf, ValidatePdfSignature};
use Illuminate\Support\{Str, Facades\File, Fluent};
use Illuminate\Http\UploadedFile;
use Symfony\Component\Process\Process;

if (!function_exists('signPdf')) {
    /**
     * @throws Throwable
     */
    function signPdfFromFile(string $pfxPath, string $password, string $pdfPath, string $mode = SignaturePdf::MODE_RESOURCE)
    {
        return (new SignaturePdf(
            $pdfPath,
            (new ManageCert)->fromPfx($pfxPath, $password),
            $mode
        ))->signature();
    }
}

if (!function_exists('signPdfFromUpload')) {
    /**
     * @throws Throwable
     */
    function signPdfFromUpload(UploadedFile $uploadedPfx, string $password, string $pdfPath, string $mode = SignaturePdf::MODE_RESOURCE)
    {
        return (new SignaturePdf(
            $pdfPath,
            (new ManageCert)->fromUpload($uploadedPfx, $password),
            $mode
        ))->signature();
    }
}

if (!function_exists('encryptCertData')) {
    /**
     * @throws Throwable
     */
    function encryptCertData($uploadedOrPfxPath, string $password): Fluent
    {
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
    }
}

if (!function_exists('decryptCertData')) {
    /**
     * @throws Throwable
     */
    function decryptCertData(string $hashKey, string $encryptCert, string $password, bool $isBase64 = false): ManageCert
    {
        $cert = (new ManageCert)->setHashKey($hashKey);
        $uuid = Str::orderedUuid();
        $pfxName = "{$cert->getTempDir()}{$uuid}.pfx";

        $decryptedData = $cert->getEncrypter()->decryptString($encryptCert);
        File::put($pfxName, $isBase64 ? base64_decode($decryptedData) : $decryptedData);

        return $cert->fromPfx(
            $pfxName,
            $cert->getEncrypter()->decryptString($password)
        );
    }
}

if (!function_exists('a1TempDir')) {
    function a1TempDir(bool $tempFile = false, string $fileExt = '.pfx'): string
    {
        $tempDir = __DIR__ . '/Temp/';

        if ($tempFile) $tempDir .= Str::orderedUuid() . $fileExt;

        return $tempDir;
    }
}

if (!function_exists('validatePdfSignature')) {
    /**
     * @throws Throwable
     */
    function validatePdfSignature(string $pdfPath): Fluent
    {
        return alidatePdfSignature::from($pdfPath);
    }
}

if (!function_exists('runCliCommandProcesses')) {
    /**
     * @throws ProcessRunTimeException
     */
    function runCliCommandProcesses(string $command): void
    {
        $process = Process::fromShellCommandline($command);
        $process->run();
        while ($process->isRunning()) continue;

        if (!$process->isSuccessful()) {
            throw new ProcessRunTimeException($process->getErrorOutput());
        }

        $process->stop(1);
    }
}
