<?php

use Illuminate\Http\UploadedFile;
use LSNepomuceno\LaravelA1PdfSign\Contracts\A1PdfSign;
use LSNepomuceno\LaravelA1PdfSign\Data\EncryptedCertificate;
use LSNepomuceno\LaravelA1PdfSign\Data\SignatureReport;
use LSNepomuceno\LaravelA1PdfSign\Enums\SignatureMode;
use LSNepomuceno\LaravelA1PdfSign\Exceptions\ProcessRunTimeException;
use LSNepomuceno\LaravelA1PdfSign\Sign\ManageCert;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\Process\Process;

/*
|--------------------------------------------------------------------------
| Global helpers
|--------------------------------------------------------------------------
|
| These are the v1 public API. They are kept working, but every one of them
| now delegates to the A1PdfSign contract resolved from the container, so the
| behaviour lives in one namespaced, mockable place.
|
| Prefer the A1PdfSign facade or injecting the contract. See
| ARCHITECTURE-V2.md §1.4 and §4.
|
*/

if (!function_exists('a1PdfSign')) {
    /**
     * Resolves the package's entry point from the container.
     */
    function a1PdfSign(): A1PdfSign
    {
        return app(A1PdfSign::class);
    }
}

if (!function_exists('signPdfFromFile')) {
    /**
     * @deprecated 2.0 Use A1PdfSign::signFromFile() instead. Removed in 3.0.
     *
     * @throws Throwable
     */
    function signPdfFromFile(
        string $pfxPath,
        string $password,
        string $pdfPath,
        SignatureMode|string $mode = SignatureMode::Resource,
        bool $usePathEnv = false,
    ): BinaryFileResponse|string {
        return a1PdfSign()->signFromFile($pfxPath, $password, $pdfPath, $mode, $usePathEnv);
    }
}

if (!function_exists('signPdfFromUpload')) {
    /**
     * @deprecated 2.0 Use A1PdfSign::signFromUpload() instead. Removed in 3.0.
     *
     * @throws Throwable
     */
    function signPdfFromUpload(
        UploadedFile $uploadedPfx,
        string $password,
        string $pdfPath,
        SignatureMode|string $mode = SignatureMode::Resource,
        bool $usePathEnv = false,
    ): BinaryFileResponse|string {
        return a1PdfSign()->signFromUpload($uploadedPfx, $password, $pdfPath, $mode, $usePathEnv);
    }
}

if (!function_exists('encryptCertData')) {
    /**
     * @deprecated 2.0 Use A1PdfSign::encryptCertificate() instead. Removed in 3.0.
     *
     * @throws Throwable
     */
    function encryptCertData(
        UploadedFile|string $uploadedOrPfxPath,
        string $password,
        bool $usePathEnv = false,
    ): EncryptedCertificate {
        return a1PdfSign()->encryptCertificate($uploadedOrPfxPath, $password, $usePathEnv);
    }
}

if (!function_exists('decryptCertData')) {
    /**
     * @deprecated 2.0 Use A1PdfSign::decryptCertificate() instead. Removed in 3.0.
     *
     * @throws Throwable
     */
    function decryptCertData(
        string $hashKey,
        string $encryptCert,
        string $password,
        bool $isBase64 = false,
        bool $usePathEnv = false,
    ): ManageCert {
        return a1PdfSign()->decryptCertificate($hashKey, $encryptCert, $password, $isBase64, $usePathEnv);
    }
}

if (!function_exists('a1TempDir')) {
    /**
     * @deprecated 2.0 Use A1PdfSign::tempPath() instead. Removed in 3.0.
     */
    function a1TempDir(bool $tempFile = false, string $fileExt = '.pfx'): string
    {
        return a1PdfSign()->tempPath($tempFile, $fileExt);
    }
}

if (!function_exists('validatePdfSignature')) {
    /**
     * @deprecated 2.0 Use A1PdfSign::validate() instead. Removed in 3.0.
     *
     * @throws Throwable
     */
    function validatePdfSignature(string $pdfPath): SignatureReport
    {
        return a1PdfSign()->validate($pdfPath);
    }
}

if (!function_exists('runCliCommandProcesses')) {
    /**
     * Runs a shell command, raising ProcessRunTimeException on failure.
     *
     * This is the single point where the package shells out; the arch tests
     * assert nothing else opens a process. See ARCHITECTURE-V2.md §3a.
     *
     * @throws ProcessRunTimeException
     */
    function runCliCommandProcesses(string $command, bool $usePathEnv = false): void
    {
        $process = Process::fromShellCommandline(
            command: $command,
            env: $usePathEnv ? ['PATH' => getenv('PATH')] : null,
        );

        $process->run();

        if (!$process->isSuccessful()) {
            throw new ProcessRunTimeException($process->getErrorOutput());
        }

        $process->stop(1);
    }
}
