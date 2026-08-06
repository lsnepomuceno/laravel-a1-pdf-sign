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
use LSNepomuceno\LaravelA1PdfSign\Exceptions\FileNotFoundException;
use LSNepomuceno\LaravelA1PdfSign\Signing\PendingSignature;
use LSNepomuceno\LaravelA1PdfSign\Support\Files;
use SensitiveParameter;

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
        ?bool $usePathEnv = null,
    ): SignedPdf {
        return $this->newSignature()
                ->usingCertificate($this->read(Files::read($pfxPath), $password, $usePathEnv))
                ->pdf($pdfPath)
                ->sign();
    }

    public function signFromUpload(
        UploadedFile $uploadedPfx,
        #[SensitiveParameter]
        string $password,
        string $pdfPath,
        ?bool $usePathEnv = null,
    ): SignedPdf {
        return $this->newSignature()
                ->usingCertificate($this->read(self::uploadedBytes($uploadedPfx), $password, $usePathEnv))
                ->pdf($pdfPath)
                ->sign();
    }

    public function encryptCertificate(
        UploadedFile|string $uploadedOrPfxPath,
        #[SensitiveParameter]
        string $password,
        ?bool $usePathEnv = null,
    ): EncryptedCertificate {
        $bytes = $uploadedOrPfxPath instanceof UploadedFile
            ? self::uploadedBytes($uploadedOrPfxPath)
            : Files::read($uploadedOrPfxPath);

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
