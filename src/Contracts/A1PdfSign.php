<?php

declare(strict_types=1);

namespace LSNepomuceno\LaravelA1PdfSign\Contracts;

use Illuminate\Http\UploadedFile;
use LSNepomuceno\Signet\Contracts\{PdfDestination, PdfSource};
use LSNepomuceno\Signet\Data\{Certificate,
    EncryptedCertificate,
    PreparedSignature,
    SealPlacement,
    SignatureField,
    SignatureReport,
    SignedPdf};
use LSNepomuceno\Signet\IcpBrasil\Data\Report;
use LSNepomuceno\Signet\Signing\PendingSignature;
use LSNepomuceno\Signet\Validation\TrustStore;

/**
 * The package's entry point, and the whole of what it adds to signet-pdf.
 *
 * Every method here either delegates to `LSNepomuceno\Signet\Signet` or does
 * something only a Laravel application can ask for. Anything that signs,
 * validates or reads bytes lives in signet-pdf and is reached through the
 * facade rather than reimplemented
 * (docs/decisions/0039-the-core-lives-in-signet-pdf.md).
 */
interface A1PdfSign
{
    /**
     * The fluent builder, and the primary way to sign.
     */
    public function newSignature(): PendingSignature;

    /**
     * Signs a PDF with a certificate read from a .pfx file on disk.
     *
     * @throws \Throwable
     */
    public function signFromFile(
        string $pfxPath,
        string $password,
        string $pdfPath,
        ?bool $usePathEnv = null,
    ): SignedPdf;

    /**
     * The same, from PEM.
     *
     * @throws \Throwable
     */
    public function signFromPem(
        string $pemPath,
        string $password,
        string $pdfPath,
        ?string $privateKeyPath = null,
    ): SignedPdf;

    /**
     * The same, from an upload, which is the one entry point signet-pdf cannot
     * offer: it takes `Illuminate\Http\UploadedFile`.
     *
     * @throws \Throwable
     */
    public function signFromUpload(
        UploadedFile $uploadedPfx,
        string $password,
        string $pdfPath,
        ?bool $usePathEnv = null,
    ): SignedPdf;

    /**
     * Seals a certificate and its password, returning the material and the key
     * that opens it. The key is not stored: without it the pair cannot be read
     * back.
     *
     * @throws \Throwable
     */
    public function encryptCertificate(
        UploadedFile|string $uploadedOrPfxPath,
        string $password,
        ?bool $usePathEnv = null,
    ): EncryptedCertificate;

    /**
     * Restores what encryptCertificate() sealed.
     *
     * @throws \Throwable
     */
    public function decryptCertificate(
        string $hashKey,
        string $encryptedCertificate,
        string $password,
        bool $isBase64 = false,
        ?bool $usePathEnv = null,
    ): Certificate;

    /**
     * Verifies every signature in a document cryptographically.
     *
     * @throws \Throwable
     */
    public function validate(
        string|PdfSource $pdfPath,
        ?TrustStore $trust = null,
        string $documentPassword = '',
    ): SignatureReport;

    /**
     * The signature fields a document carries, filled or empty.
     *
     * @return list<SignatureField>
     *
     * @throws \Throwable
     */
    public function signatureFields(string|PdfSource $pdfPath): array;

    /**
     * Appends a fresh archive timestamp to an already signed document.
     *
     * @throws \Throwable
     */
    public function extendArchive(string|PdfSource $pdfPath, string $documentPassword = ''): SignedPdf;

    /**
     * What a Brazilian signer is known by, read out of the certificate.
     *
     * @throws \Throwable
     */
    public function icpBrasil(string $pfxPath, string $password = ''): Report;

    /**
     * Finishes a signature whose CMS was produced elsewhere.
     *
     * The other half of `newSignature()->…->prepare()`: the document is
     * digested here, the digest is signed by something that holds the private
     * key (an HSM, a remote service, a smartcard), and the detached CMS comes
     * back to be written in. **The private key never enters this process.**
     *
     * @throws \Throwable
     */
    public function complete(
        PreparedSignature $prepared,
        string $cms,
        ?Certificate $certificate = null,
        string $documentPassword = '',
    ): SignedPdf;

    /**
     * Adds an empty signature field for somebody else to fill later.
     *
     * A null placement leaves it invisible, which is the safe default: a
     * rectangle is only meaningful against a page whose size the caller knows.
     *
     * @throws \Throwable
     */
    public function addSignatureField(
        string|PdfSource $pdfPath,
        string $name,
        ?SealPlacement $placement = null,
        string $documentPassword = '',
    ): SignedPdf;

    /**
     * A document on a Laravel disk, as a source the builder accepts.
     *
     * ```php
     * A1PdfSign::newSignature()
     *     ->certificate($pfx, $password)
     *     ->from(A1PdfSign::fromDisk('s3', 'contracts/deal.pdf'))
     *     ->sign()
     *     ->writeTo(A1PdfSign::toDisk('s3', 'contracts/deal-signed.pdf'));
     * ```
     */
    public function fromDisk(string $disk, string $path): PdfSource;

    /**
     * A document that arrived in a request, as a source.
     */
    public function fromUpload(UploadedFile $file): PdfSource;

    /**
     * Where a signed document should land.
     *
     * A null path uses the name the document already carries.
     */
    public function toDisk(string $disk, ?string $path = null): PdfDestination;

    /**
     * The configured temporary directory, or a path inside it.
     *
     * Laravel specific: it honours `a1-pdf-sign.temp_path` and creates the
     * directory, which is what a queued job on a fresh container needs.
     */
    public function tempPath(bool $tempFile = false, string $fileExt = '.pfx'): string;
}
