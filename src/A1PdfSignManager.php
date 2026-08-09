<?php

namespace LSNepomuceno\LaravelA1PdfSign;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use LSNepomuceno\LaravelA1PdfSign\Certificates\CertificateParser;
use LSNepomuceno\LaravelA1PdfSign\Certificates\CertificateVault;
use LSNepomuceno\LaravelA1PdfSign\Certificates\PemCertificateReader;
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

    /**
     * Delegates to the builder rather than reading here: PEM needs no
     * conversion, so there is no reader selection to make and nothing this
     * method could add over certificatePem().
     */
    public function signFromPem(
        string $pemPath,
        #[SensitiveParameter]
        string $password,
        string $pdfPath,
        ?string $privateKeyPath = null,
    ): SignedPdf {
        return $this->newSignature()
                ->certificatePem($pemPath, $privateKeyPath, $password)
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
            $this->readAnyEncoding($bytes, $password, $usePathEnv),
            $password,
        );
    }

    /**
     * encryptCertificate() stores the PEM bundle, so this parses it directly.
     * The v1 helper wrote it to a .pfx and fed it to `openssl pkcs12 -in`,
     * which expects binary PKCS#12 and always failed. See
     * docs/history/v2-modernization.md.
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
     * Reads whichever encoding turned up.
     *
     * Signing keeps explicit siblings, signFromFile() and signFromPem(), so
     * the caller states what it holds. encryptCertificate() has no sibling and
     * takes "a certificate" generically, so it detects instead. The two
     * encodings are trivially distinguishable, text marker against binary, so
     * there is nothing to guess at.
     */
    private function readAnyEncoding(
        string $contents,
        #[SensitiveParameter]
        string $password,
        ?bool $usePathEnv,
    ): Certificate {
        if (PemCertificateReader::looksLikePem($contents)) {
            return $this->container->make(PemCertificateReader::class)->read($contents, $password);
        }

        return $this->read($contents, $password, $usePathEnv);
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
