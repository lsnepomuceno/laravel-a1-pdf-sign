<?php

namespace LSNepomuceno\LaravelA1PdfSign\Contracts;

use Illuminate\Http\UploadedFile;
use LSNepomuceno\LaravelA1PdfSign\Data\EncryptedCertificate;
use LSNepomuceno\LaravelA1PdfSign\Data\SignatureReport;
use LSNepomuceno\LaravelA1PdfSign\Enums\SignatureMode;
use LSNepomuceno\LaravelA1PdfSign\Sign\ManageCert;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * The package's entry point.
 *
 * This is the namespaced, injectable equivalent of the global helper
 * functions, which now delegate here. Resolve it from the container, or use
 * the A1PdfSign facade.
 *
 * The fluent signing builder described in ARCHITECTURE-V2.md §2 attaches to
 * this contract in PR 7; the methods below mirror the v1 helpers so the
 * migration is a rename, not a rewrite.
 */
interface A1PdfSign
{
    /**
     * Signs a PDF with a certificate read from a .pfx file on disk.
     *
     * @throws \Throwable
     */
    public function signFromFile(
        string $pfxPath,
        string $password,
        string $pdfPath,
        SignatureMode|string|null $mode = null,
        ?bool $usePathEnv = null,
    ): BinaryFileResponse|string;

    /**
     * Signs a PDF with a certificate read from an uploaded .pfx file.
     *
     * @throws \Throwable
     */
    public function signFromUpload(
        UploadedFile $uploadedPfx,
        string $password,
        string $pdfPath,
        SignatureMode|string|null $mode = null,
        ?bool $usePathEnv = null,
    ): BinaryFileResponse|string;

    /**
     * Encrypts a certificate and its password for storage.
     *
     * The returned hash is the key both values were encrypted with and is
     * required by decryptCertificate().
     *
     * @throws \Throwable
     */
    public function encryptCertificate(
        UploadedFile|string $uploadedOrPfxPath,
        string $password,
        ?bool $usePathEnv = null,
    ): EncryptedCertificate;

    /**
     * Restores a certificate previously encrypted by encryptCertificate().
     *
     * @throws \Throwable
     */
    public function decryptCertificate(
        string $hashKey,
        string $encryptedCertificate,
        string $password,
        bool $isBase64 = false,
        ?bool $usePathEnv = null,
    ): ManageCert;

    /**
     * Inspects the signature embedded in a PDF.
     *
     * @throws \Throwable
     */
    public function validate(string $pdfPath): SignatureReport;

    /**
     * The directory the package writes temporary files to, or a path inside it
     * when $tempFile is true.
     */
    public function tempPath(bool $tempFile = false, string $fileExt = '.pfx'): string;
}
