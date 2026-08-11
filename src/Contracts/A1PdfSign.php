<?php

namespace LSNepomuceno\LaravelA1PdfSign\Contracts;

use Illuminate\Http\UploadedFile;
use LSNepomuceno\LaravelA1PdfSign\Data\Certificate;
use LSNepomuceno\LaravelA1PdfSign\Data\EncryptedCertificate;
use LSNepomuceno\LaravelA1PdfSign\Data\SignatureReport;
use LSNepomuceno\LaravelA1PdfSign\Data\SignedPdf;
use LSNepomuceno\LaravelA1PdfSign\Validation\TrustStore;

/**
 * The package's entry point.
 *
 * This is the namespaced, injectable equivalent of the global helper
 * functions, which now delegate here. Resolve it from the container, or use
 * the A1PdfSign facade.
 *
 * The fluent signing builder described in docs/spec/public-api.md attaches to
 * this contract in PR 7; the methods below mirror the v1 helpers so the
 * migration is a rename, not a rewrite.
 */
interface A1PdfSign
{
    /**
     * Signs a PDF with a certificate read from a .pfx file on disk.
     *
     * Returns the document; the caller picks how it is delivered.
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
     * Signs a PDF with a certificate read from PEM files on disk.
     *
     * $privateKeyPath is null when the key sits in the same file as the
     * certificate. $password is empty when the key is unencrypted, which PEM
     * permits and PKCS#12 does not. See docs/decisions/0007-pem-second-entry-one-pipeline.md.
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
     * Signs a PDF with a certificate read from an uploaded .pfx file.
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
    ): Certificate;

    /**
     * Inspects the signature embedded in a PDF.
     *
     * @throws \Throwable
     */
    public function validate(string $pdfPath, ?TrustStore $trust = null): SignatureReport;

    /**
     * Lists the signature fields a document already carries, filled or empty.
     *
     * An application that signs into a template usually has to show its fields
     * before it can pick one, so discovery is exposed on its own rather than
     * only as an error message from intoField()
     * (docs/decisions/0013-signing-into-an-existing-field.md).
     *
     * @return list<\LSNepomuceno\LaravelA1PdfSign\Data\SignatureField> In the
     *                                                                  order the
     *                                                                  form declares.
     *
     * @throws \Throwable
     */
    public function signatureFields(string $pdfPath): array;

    /**
     * Adds a fresh archive timestamp to a document that already carries a
     * signature, extending the PAdES B-LTA chain (ETSI EN 319 142-1).
     *
     * No certificate is involved: a DocTimeStamp is signed by the authority,
     * not by the signer, so this is something a scheduled job can do to an
     * archive with no key material anywhere near it.
     *
     * @throws \LSNepomuceno\LaravelA1PdfSign\Exceptions\CertificationException
     * @throws \LSNepomuceno\LaravelA1PdfSign\Exceptions\FileNotFoundException
     * @throws \LSNepomuceno\LaravelA1PdfSign\Exceptions\HasNoSignatureOrInvalidPkcs7Exception
     * @throws \LSNepomuceno\LaravelA1PdfSign\Exceptions\InvalidPdfFileException
     * @throws \LSNepomuceno\LaravelA1PdfSign\Exceptions\ProcessRunTimeException
     *
     * @see docs/decisions/0022-the-archive-timestamp-is-a-chain.md
     */
    public function extendArchive(string $pdfPath): SignedPdf;

    /**
     * Starts a fluent signature. Nothing happens until sign() is called.
     */
    public function newSignature(): \LSNepomuceno\LaravelA1PdfSign\Signing\PendingSignature;

    /**
     * The directory the package writes temporary files to, or a path inside it
     * when $tempFile is true.
     */
    public function tempPath(bool $tempFile = false, string $fileExt = '.pfx'): string;
}
