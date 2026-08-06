<?php

namespace LSNepomuceno\LaravelA1PdfSign;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use LSNepomuceno\LaravelA1PdfSign\Certificates\CertificateParser;
use LSNepomuceno\LaravelA1PdfSign\Certificates\CertificateVault;
use LSNepomuceno\LaravelA1PdfSign\Certificates\ReaderFactory;
use LSNepomuceno\LaravelA1PdfSign\Contracts\A1PdfSign;
use LSNepomuceno\LaravelA1PdfSign\Contracts\SignatureValidator;
use LSNepomuceno\LaravelA1PdfSign\Data\Certificate;
use LSNepomuceno\LaravelA1PdfSign\Data\EncryptedCertificate;
use LSNepomuceno\LaravelA1PdfSign\Data\SignatureReport;
use LSNepomuceno\LaravelA1PdfSign\Data\SignedPdf;
use LSNepomuceno\LaravelA1PdfSign\Enums\SignatureMode;
use LSNepomuceno\LaravelA1PdfSign\Signing\PendingSignature;
use SensitiveParameter;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Default implementation of the package's entry point.
 *
 * Every argument the v1 helpers took as a required boolean is nullable here:
 * null means "use the configured default", so callers stop repeating
 * infrastructure decisions at every call site.
 */
final readonly class A1PdfSignManager implements A1PdfSign
{
    public function __construct(
        private Config $config,
        private CertificateParser $parser,
        private Container $container,
        private ReaderFactory $readers,
    ) {}

    public function newSignature(): PendingSignature
    {
        return $this->container->make(PendingSignature::class);
    }

    public function signFromFile(
        string $pfxPath,
        #[SensitiveParameter]
        string $password,
        string $pdfPath,
        SignatureMode|string|null $mode = null,
        ?bool $usePathEnv = null,
    ): BinaryFileResponse|string {
        return $this->deliver(
            $this->newSignature()
                ->usingCertificate($this->read(File::get($pfxPath), $password, $usePathEnv))
                ->pdf($pdfPath)
                ->sign(),
            $mode,
        );
    }

    public function signFromUpload(
        UploadedFile $uploadedPfx,
        #[SensitiveParameter]
        string $password,
        string $pdfPath,
        SignatureMode|string|null $mode = null,
        ?bool $usePathEnv = null,
    ): BinaryFileResponse|string {
        return $this->deliver(
            $this->newSignature()
                ->usingCertificate($this->read($uploadedPfx->get(), $password, $usePathEnv))
                ->pdf($pdfPath)
                ->sign(),
            $mode,
        );
    }

    public function encryptCertificate(
        UploadedFile|string $uploadedOrPfxPath,
        #[SensitiveParameter]
        string $password,
        ?bool $usePathEnv = null,
    ): EncryptedCertificate {
        $bytes = $uploadedOrPfxPath instanceof UploadedFile
            ? $uploadedOrPfxPath->get()
            : File::get($uploadedOrPfxPath);

        // The hash it returns is required by decryptCertificate(); without it
        // the pair cannot be read back.
        return CertificateVault::create()->seal(
            $this->read($bytes, $password, $usePathEnv),
            $password,
        );
    }

    /**
     * encryptCertificate() stores the PEM bundle, so this parses it directly.
     * The v1 helper wrote it to a .pfx and fed it to `openssl pkcs12 -in`,
     * which expects binary PKCS#12 and always failed — ARCHITECTURE-V2.md
     * §1.14.
     */
    public function decryptCertificate(
        #[SensitiveParameter]
        string $hashKey,
        string $encryptedCertificate,
        #[SensitiveParameter]
        string $password,
        bool $isBase64 = false,
        ?bool $usePathEnv = null,
    ): Certificate {
        return CertificateVault::withKey($hashKey)->open(
            $this->parser,
            $encryptedCertificate,
            $password,
            $isBase64,
        );
    }

    public function validate(string $pdfPath): SignatureReport
    {
        return $this->container->make(SignatureValidator::class)->validateFile($pdfPath);
    }

    public function tempPath(bool $tempFile = false, string $fileExt = '.pfx'): string
    {
        $configured = $this->config->get('a1-pdf-sign.temp_path');

        $path = is_string($configured) && $configured !== ''
            ? rtrim($configured, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR
            : rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

        if (! File::isDirectory($path)) {
            File::makeDirectory($path, recursive: true);
        }

        return $tempFile ? $path . Str::orderedUuid() . $fileExt : $path;
    }

    private function read(
        string $pfxContents,
        #[SensitiveParameter]
        string $password,
        ?bool $usePathEnv,
    ): Certificate {
        return $this->readers->make(usePathEnv: $usePathEnv)->read($pfxContents, $password);
    }

    /**
     * Signing produces bytes; the mode only picks how they are handed back.
     * The v1 flow wrote them to disk and read them straight back for the bytes
     * case (§1.8).
     */
    private function deliver(
        SignedPdf $signed,
        SignatureMode|string|null $mode,
    ): BinaryFileResponse|string {
        return SignatureMode::resolve($mode ?? SignatureMode::Resource) === SignatureMode::Download
            ? $signed->download()
            : $signed->contents();
    }
}
