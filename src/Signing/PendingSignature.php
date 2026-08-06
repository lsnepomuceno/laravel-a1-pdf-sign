<?php

namespace LSNepomuceno\LaravelA1PdfSign\Signing;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use LSNepomuceno\LaravelA1PdfSign\Contracts\CertificateReader;
use LSNepomuceno\LaravelA1PdfSign\Contracts\PdfSigner;
use LSNepomuceno\LaravelA1PdfSign\Data\Certificate;
use LSNepomuceno\LaravelA1PdfSign\Data\SignatureInfo;
use LSNepomuceno\LaravelA1PdfSign\Data\SignedPdf;
use LSNepomuceno\LaravelA1PdfSign\Exceptions\FileNotFoundException;
use LSNepomuceno\LaravelA1PdfSign\Exceptions\InvalidPFXException;
use SensitiveParameter;

/**
 * Collects everything a signature needs, then produces it.
 *
 * Nothing happens until sign() is called, and sign() returns the document
 * rather than a transport, so the caller decides afterwards whether it becomes
 * bytes, a file or a download.
 */
final class PendingSignature
{
    private ?Certificate $certificate = null;

    private ?string $pdfContents = null;

    private string $fileName = '';

    private SignatureInfo $info;

    private string $fieldName = 'Signature';

    public function __construct(
        private readonly CertificateReader $reader,
        private readonly PdfSigner $signer,
    ) {
        $this->info = new SignatureInfo();
    }

    /**
     * @throws FileNotFoundException
     * @throws InvalidPFXException
     */
    public function certificate(
        string $pfxPath,
        #[SensitiveParameter]
        string $password,
    ): self {
        if (! str_ends_with(strtolower($pfxPath), '.pfx') && ! str_ends_with(strtolower($pfxPath), '.p12')) {
            throw new InvalidPFXException($pfxPath);
        }

        if (! File::exists($pfxPath)) {
            throw new FileNotFoundException($pfxPath);
        }

        $this->certificate = $this->reader->read(File::get($pfxPath), $password);

        return $this;
    }

    public function certificateFromUpload(
        UploadedFile $uploadedPfx,
        #[SensitiveParameter]
        string $password,
    ): self {
        $this->certificate = $this->reader->read($uploadedPfx->get(), $password);

        return $this;
    }

    public function usingCertificate(Certificate $certificate): self
    {
        $this->certificate = $certificate;

        return $this;
    }

    /**
     * @throws FileNotFoundException
     */
    public function pdf(string $pdfPath): self
    {
        if (! File::exists($pdfPath)) {
            throw new FileNotFoundException($pdfPath);
        }

        $this->pdfContents = File::get($pdfPath);
        $this->fileName = pathinfo($pdfPath, PATHINFO_BASENAME);

        return $this;
    }

    public function pdfContents(string $contents, string $fileName = ''): self
    {
        $this->pdfContents = $contents;
        $this->fileName = $fileName;

        return $this;
    }

    public function info(
        ?string $name = null,
        ?string $location = null,
        ?string $reason = null,
        ?string $contactInfo = null,
    ): self {
        $this->info = new SignatureInfo($name, $location, $reason, $contactInfo);

        return $this;
    }

    /**
     * Names the signature field. Successive signers must not share one.
     */
    public function fieldName(string $fieldName): self
    {
        $this->fieldName = $fieldName;

        return $this;
    }

    /**
     * @throws FileNotFoundException
     */
    public function sign(): SignedPdf
    {
        if ($this->certificate === null) {
            throw new FileNotFoundException('no certificate given; call certificate() first');
        }

        if ($this->pdfContents === null) {
            throw new FileNotFoundException('no document given; call pdf() first');
        }

        $signed = $this->signer->sign(
            $this->pdfContents,
            $this->certificate,
            $this->info,
            $this->fieldName,
        );

        return new SignedPdf($signed->contents, $this->signedFileName());
    }

    private function signedFileName(): string
    {
        if ($this->fileName === '') {
            return '';
        }

        $name = pathinfo($this->fileName, PATHINFO_FILENAME);

        return "{$name}_signed.pdf";
    }
}
