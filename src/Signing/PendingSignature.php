<?php

namespace LSNepomuceno\LaravelA1PdfSign\Signing;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use LSNepomuceno\LaravelA1PdfSign\Certificates\PemCertificateReader;
use LSNepomuceno\LaravelA1PdfSign\Contracts\CertificateReader;
use LSNepomuceno\LaravelA1PdfSign\Contracts\PdfSigner;
use LSNepomuceno\LaravelA1PdfSign\Contracts\SealRenderer;
use LSNepomuceno\LaravelA1PdfSign\Data\Certificate;
use LSNepomuceno\LaravelA1PdfSign\Data\SealPlacement;
use LSNepomuceno\LaravelA1PdfSign\Data\SignatureInfo;
use LSNepomuceno\LaravelA1PdfSign\Data\SignedPdf;
use LSNepomuceno\LaravelA1PdfSign\Enums\FontSize;
use LSNepomuceno\LaravelA1PdfSign\Enums\SignatureProfile;
use LSNepomuceno\LaravelA1PdfSign\Exceptions\FileNotFoundException;
use LSNepomuceno\LaravelA1PdfSign\Exceptions\InvalidPemContentException;
use LSNepomuceno\LaravelA1PdfSign\Exceptions\InvalidPFXException;
use LSNepomuceno\LaravelA1PdfSign\Support\Files;
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

    private ?SealPlacement $placement = null;

    private bool $withSeal = false;

    private FontSize|string|null $sealFontSize = null;

    private bool $sealShowsExpiry = false;

    private SignatureProfile|string|null $profile = null;

    public function __construct(
        private readonly CertificateReader $reader,
        private readonly PdfSigner $signer,
        private readonly SealRenderer $sealRenderer,
        private readonly PemCertificateReader $pemReader,
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

        $this->certificate = $this->reader->read(Files::read($pfxPath), $password);

        return $this;
    }

    public function certificateFromUpload(
        UploadedFile $uploadedPfx,
        #[SensitiveParameter]
        string $password,
    ): self {
        $this->certificate = $this->reader->read(self::uploadedBytes($uploadedPfx), $password);

        return $this;
    }

    /**
     * Reads a PEM certificate, with the private key in the same file or in one
     * of its own.
     *
     * Unlike certificate(), this does not gate on the file extension. PEM ships
     * as .pem, .crt, .cer, .key and .txt, so the format is decided by content
     * (ARCHITECTURE-V2.md §3i).
     *
     * @param  string  $password  Empty when the private key is unencrypted — legal and
     *                            common for PEM, impossible for PKCS#12.
     *
     * @throws FileNotFoundException
     * @throws InvalidPemContentException
     */
    public function certificatePem(
        string $certificatePath,
        ?string $privateKeyPath = null,
        #[SensitiveParameter]
        string $password = '',
    ): self {
        return $this->certificateFromPem(
            Files::read($certificatePath),
            $privateKeyPath === null ? null : Files::read($privateKeyPath),
            $password,
        );
    }

    /**
     * The same, from bytes the caller already holds — an upload, a secret
     * manager, a database column.
     *
     * @throws InvalidPemContentException
     */
    public function certificateFromPem(
        string $contents,
        ?string $privateKey = null,
        #[SensitiveParameter]
        string $password = '',
    ): self {
        $this->certificate = $privateKey === null
            ? $this->pemReader->read($contents, $password)
            : $this->pemReader->readPair($contents, $privateKey, $password);

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

        $this->pdfContents = Files::read($pdfPath);
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
     * Makes the signature visible, rendering a seal from the certificate.
     *
     * Position and size default to the configured placement; pass a
     * SealPlacement to override it.
     */
    public function seal(
        ?SealPlacement $placement = null,
        FontSize|string|null $fontSize = null,
        bool $showExpiry = false,
    ): self {
        $this->withSeal = true;
        $this->placement = $placement;
        $this->sealFontSize = $fontSize;
        $this->sealShowsExpiry = $showExpiry;

        return $this;
    }

    /**
     * Stamps a seal image the caller already has, skipping the renderer.
     */
    public function sealFrom(string $imagePath, ?SealPlacement $placement = null): self
    {
        $this->withSeal = true;
        $this->placement = ($placement ?? $this->defaultPlacement())->withImagePath($imagePath);

        return $this;
    }

    /**
     * Chooses the signature profile.
     *
     * Defaults to PAdES B-B. B-T and above request an RFC 3161 timestamp and
     * therefore need a1-pdf-sign.signature.timestamp.url configured.
     */
    public function profile(SignatureProfile|string $profile): self
    {
        $this->profile = $profile;

        return $this;
    }

    /**
     * Shorthand for the timestamped profile, PAdES B-T.
     */
    public function timestamp(): self
    {
        return $this->profile(SignatureProfile::PadesBT);
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

        $seal = $this->withSeal
            ? $this->sealRenderer->render($this->certificate, $this->sealFontSize, $this->sealShowsExpiry)
            : null;

        $signed = $this->signer->sign(
            $this->pdfContents,
            $this->certificate,
            $this->info,
            $this->fieldName,
            $seal,
            $seal !== null ? ($this->placement ?? $this->defaultPlacement()) : null,
            SignatureProfile::resolve($this->profile ?? $this->configuredProfile()),
        );

        return new SignedPdf($signed->contents, $this->signedFileName());
    }

    private function configuredProfile(): ?string
    {
        $value = config('a1-pdf-sign.signature.profile');

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function defaultPlacement(): SealPlacement
    {
        return new SealPlacement('');
    }

    private function signedFileName(): string
    {
        if ($this->fileName === '') {
            return '';
        }

        $name = pathinfo($this->fileName, PATHINFO_FILENAME);

        return "{$name}_signed.pdf";
    }

    /**
     * UploadedFile::get() returns false when the temporary upload is gone.
     *
     * @throws FileNotFoundException
     */
    private static function uploadedBytes(UploadedFile $file): string
    {
        $contents = $file->get();

        if ($contents === false) {
            throw new FileNotFoundException($file->getClientOriginalName());
        }

        return $contents;
    }
}
