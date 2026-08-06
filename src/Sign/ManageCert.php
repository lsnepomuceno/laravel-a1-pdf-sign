<?php

namespace LSNepomuceno\LaravelA1PdfSign\Sign;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Encryption\EncryptException;
use Illuminate\Encryption\Encrypter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use LSNepomuceno\LaravelA1PdfSign\Certificates\CertificateParser;
use LSNepomuceno\LaravelA1PdfSign\Certificates\CertificateVault;
use LSNepomuceno\LaravelA1PdfSign\Contracts\A1PdfSign;
use LSNepomuceno\LaravelA1PdfSign\Contracts\CertificateReader;
use LSNepomuceno\LaravelA1PdfSign\Data\Certificate;
use LSNepomuceno\LaravelA1PdfSign\Exceptions\CertificateOutputNotFoundException;
use LSNepomuceno\LaravelA1PdfSign\Exceptions\FileNotFoundException;
use LSNepomuceno\LaravelA1PdfSign\Exceptions\InvalidCertificateContentException;
use LSNepomuceno\LaravelA1PdfSign\Exceptions\InvalidPFXException;
use LSNepomuceno\LaravelA1PdfSign\Exceptions\Invalidx509PrivateKeyException;
use LSNepomuceno\LaravelA1PdfSign\Exceptions\ProcessRunTimeException;
use LSNepomuceno\LaravelA1PdfSign\Testing\DebugCertificate;
use SensitiveParameter;

/**
 * Holds a certificate while it is being read and used.
 *
 * As of PR 6 this delegates: reading is a {@see CertificateReader}, encryption
 * is a {@see CertificateVault}. It survives as the object SignaturePdf and
 * SealImage receive, and is replaced by the fluent builder in PR 7.
 */
class ManageCert
{
    /** @deprecated 2.0 Use {@see CertificateVault::CIPHER}. */
    public const string CIPHER = CertificateVault::CIPHER;

    private Certificate $certificate;

    private CertificateVault $vault;

    private bool $preservePfx = false;

    private bool $isLegacy = false;

    public function __construct(
        private readonly ?CertificateReader $reader = null,
        private readonly ?CertificateParser $parser = null,
    ) {
        $this->vault = CertificateVault::create();
    }

    public function setPreservePfx(bool $preservePfx = true): self
    {
        $this->preservePfx = $preservePfx;

        return $this;
    }

    public function setIsLegacy(bool $isLegacy = true): self
    {
        $this->isLegacy = $isLegacy;

        return $this;
    }

    /**
     * @throws CertificateOutputNotFoundException
     * @throws FileNotFoundException
     * @throws InvalidCertificateContentException
     * @throws InvalidPFXException
     * @throws Invalidx509PrivateKeyException
     * @throws ProcessRunTimeException
     */
    public function fromPfx(
        string $pfxPath,
        #[SensitiveParameter]
        string $password,
        bool $usePathEnv = false,
    ): self {
        if (! Str::of($pfxPath)->lower()->endsWith('.pfx')) {
            throw new InvalidPFXException($pfxPath);
        }

        if (! File::exists($pfxPath)) {
            throw new FileNotFoundException($pfxPath);
        }

        $this->certificate = $this->resolveReader($usePathEnv)->read(File::get($pfxPath), $password);

        if (! $this->preservePfx) {
            File::delete($pfxPath);
        }

        return $this;
    }

    /**
     * @throws CertificateOutputNotFoundException
     * @throws InvalidCertificateContentException
     * @throws Invalidx509PrivateKeyException
     * @throws ProcessRunTimeException
     */
    public function fromUpload(
        UploadedFile $uploadedPfx,
        #[SensitiveParameter]
        string $password,
        bool $usePathEnv = false,
    ): self {
        // The upload never reaches disk under our control; the reader takes bytes.
        $this->certificate = $this->resolveReader($usePathEnv)->read($uploadedPfx->get(), $password);

        return $this;
    }

    /**
     * @throws InvalidCertificateContentException
     * @throws Invalidx509PrivateKeyException
     */
    public function setCertContent(
        string $certContent,
        #[SensitiveParameter]
        string $password = '',
    ): self {
        $this->certificate = $this->parser()->parse($certContent, $password);

        return $this;
    }

    /**
     * @throws InvalidCertificateContentException
     */
    public function validate(): void
    {
        if (! isset($this->certificate)) {
            throw new InvalidCertificateContentException();
        }
    }

    public function getCert(): Certificate
    {
        $this->validate();

        return $this->certificate;
    }

    public function getTempDir(): string
    {
        return app(A1PdfSign::class)->tempPath();
    }

    public function generateHashKey(): self
    {
        $this->vault = CertificateVault::create();

        return $this;
    }

    public function setHashKey(#[SensitiveParameter] string $hashKey): self
    {
        $this->vault = CertificateVault::withKey($hashKey);

        return $this;
    }

    public function getHashKey(): string
    {
        return $this->vault->key();
    }

    public function getEncrypter(): Encrypter
    {
        return $this->vault->encrypter();
    }

    public function getVault(): CertificateVault
    {
        return $this->vault;
    }

    /**
     * @throws EncryptException
     */
    public function encryptBase64BlobString(string $blobString): string
    {
        return $this->getEncrypter()->encryptString(base64_encode($blobString));
    }

    /**
     * @throws DecryptException
     */
    public function decryptBase64BlobString(string $encryptedBlobString): string
    {
        return base64_decode($this->getEncrypter()->decryptString($encryptedBlobString));
    }

    /**
     * @deprecated 2.0 Use {@see DebugCertificate::make()}. Removed in 3.0.
     *
     * @return array{0: string, 1: string}|static
     *
     * @throws CertificateOutputNotFoundException
     * @throws FileNotFoundException
     * @throws InvalidCertificateContentException
     * @throws InvalidPFXException
     * @throws Invalidx509PrivateKeyException
     * @throws ProcessRunTimeException
     */
    public function makeDebugCertificate(bool $returnPathAndPass = false, bool $wrongPass = false): array|static
    {
        [$pfx, $password] = DebugCertificate::make();

        $path = $this->getTempDir() . Str::orderedUuid() . '.pfx';
        File::put($path, $pfx);

        if ($returnPathAndPass) {
            return [$path, $password];
        }

        return $this->fromPfx($path, $wrongPass ? 'wrongPass' : $password);
    }

    private function resolveReader(bool $usePathEnv): CertificateReader
    {
        if ($this->reader !== null) {
            return $this->reader;
        }

        /** @var \LSNepomuceno\LaravelA1PdfSign\Certificates\ReaderFactory $factory */
        $factory = app(\LSNepomuceno\LaravelA1PdfSign\Certificates\ReaderFactory::class);

        return $factory->make($this->isLegacy, $usePathEnv);
    }

    private function parser(): CertificateParser
    {
        return $this->parser ?? app(CertificateParser::class);
    }
}
