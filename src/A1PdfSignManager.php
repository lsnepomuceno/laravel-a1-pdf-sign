<?php

declare(strict_types=1);

namespace LSNepomuceno\LaravelA1PdfSign;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{File, Storage};
use Illuminate\Support\Str;
use LSNepomuceno\LaravelA1PdfSign\Adapters\IlluminateEncrypter;
use LSNepomuceno\LaravelA1PdfSign\Contracts\A1PdfSign;
use LSNepomuceno\LaravelA1PdfSign\Io\{DiskDestination, DiskSource, UploadedFileSource};
use LSNepomuceno\Signet\Certificates\{CertificateParser, CertificateVault, PemCertificateReader, ReaderFactory};
use LSNepomuceno\Signet\Contracts\{CertificateReader, PdfDestination, PdfSource};
use LSNepomuceno\Signet\Data\{Certificate,
    EncryptedCertificate,
    PreparedSignature,
    SealPlacement,
    SignatureReport,
    SignedPdf};
use LSNepomuceno\Signet\Exceptions\FileNotFoundException;
use LSNepomuceno\Signet\IcpBrasil\Data\Report;
use LSNepomuceno\Signet\Signet;
use LSNepomuceno\Signet\Signing\PendingSignature;
use LSNepomuceno\Signet\Support\Files;
use LSNepomuceno\Signet\Validation\TrustStore;
use SensitiveParameter;

/**
 * The entry point, and the whole of what this package adds to signet-pdf.
 *
 * Everything that reads or writes a byte delegates to `Signet`, which is
 * resolved from the container already carrying the Laravel adapters. What is
 * implemented here is what only a Laravel application can ask for: an upload,
 * and a temporary directory the application configures
 * (docs/decisions/0039-the-core-lives-in-signet-pdf.md).
 *
 * Every argument that was a required boolean in v1 is nullable: null means
 * "use the configured default", so call sites stop repeating infrastructure
 * decisions.
 */
final readonly class A1PdfSignManager implements A1PdfSign
{
    public function __construct(
        private Config $config,
        private Signet $signet,
    ) {}

    public function newSignature(): PendingSignature
    {
        return $this->signet->newSignature();
    }

    public function signFromFile(
        string $pfxPath,
        #[SensitiveParameter]
        string $password,
        string $pdfPath,
        ?bool $usePathEnv = null,
    ): SignedPdf {
        return $this->signet->signFromFile($pfxPath, $password, $pdfPath, $usePathEnv);
    }

    public function signFromPem(
        string $pemPath,
        #[SensitiveParameter]
        string $password,
        string $pdfPath,
        ?string $privateKeyPath = null,
    ): SignedPdf {
        return $this->signet->signFromPem($pemPath, $password, $pdfPath, $privateKeyPath);
    }

    /**
     * The one signing entry point signet-pdf cannot offer, because it takes a
     * framework type.
     *
     * The upload is read into memory rather than written to a temporary file:
     * a PKCS#12 bundle carries a private key, and the fewer places it is
     * written the better.
     */
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

    /**
     * Accepts an upload as well as a path, which is why it does not delegate:
     * `Signet::encryptCertificate()` reads from disk.
     */
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
        //
        // `using()` rather than `create()`: the engine's own default seals
        // with XChaCha20-Poly1305 under a 32-byte key, and this package seals
        // with Laravel's encrypter so that material written by 2.x and by 3.0
        // share one envelope.
        return CertificateVault::using(IlluminateEncrypter::generate())->seal(
            $this->readAnyEncoding($bytes, $password, $usePathEnv),
            $password,
        );
    }

    public function decryptCertificate(
        #[SensitiveParameter]
        string $hashKey,
        string $encryptedCertificate,
        #[SensitiveParameter]
        string $password,
        bool $isBase64 = false,
        ?bool $usePathEnv = null,
    ): Certificate {
        return $this->signet->decryptCertificate($hashKey, $encryptedCertificate, $password, $isBase64);
    }

    public function validate(
        string|PdfSource $pdfPath,
        ?TrustStore $trust = null,
        #[SensitiveParameter]
        string $documentPassword = '',
    ): SignatureReport {
        return $this->signet->validate($pdfPath, $trust, $documentPassword);
    }

    public function signatureFields(string|PdfSource $pdfPath): array
    {
        return $this->signet->signatureFields($pdfPath);
    }

    public function extendArchive(
        string|PdfSource $pdfPath,
        #[SensitiveParameter]
        string $documentPassword = '',
    ): SignedPdf {
        return $this->signet->extendArchive($pdfPath, $documentPassword);
    }

    public function complete(
        PreparedSignature $prepared,
        string $cms,
        ?Certificate $certificate = null,
        #[SensitiveParameter]
        string $documentPassword = '',
    ): SignedPdf {
        return $this->signet->complete($prepared, $cms, $certificate, $documentPassword);
    }

    public function addSignatureField(
        string|PdfSource $pdfPath,
        string $name,
        ?SealPlacement $placement = null,
        #[SensitiveParameter]
        string $documentPassword = '',
    ): SignedPdf {
        return $this->signet->addSignatureField($pdfPath, $name, $placement, $documentPassword);
    }

    public function icpBrasil(
        string $pfxPath,
        #[SensitiveParameter]
        string $password = '',
    ): Report {
        return $this->signet->icpBrasil($pfxPath, $password);
    }

    /**
     * A document on a Laravel disk, as a source the builder accepts.
     *
     * The disk is resolved here rather than taken as a `Filesystem`, because
     * a caller naming a disk is the common case and `Storage::disk()` is what
     * `Storage::fake()` replaces.
     */
    public function fromDisk(string $disk, string $path): PdfSource
    {
        return new DiskSource(Storage::disk($disk), $path);
    }

    public function fromUpload(UploadedFile $file): PdfSource
    {
        return new UploadedFileSource($file);
    }

    public function toDisk(string $disk, ?string $path = null): PdfDestination
    {
        return new DiskDestination(Storage::disk($disk), $path);
    }

    /**
     * The configured temporary directory, created if it is not there.
     *
     * signet-pdf has `TempDirectory` for the same job, and this stays because
     * it answers to `a1-pdf-sign.temp_path` and to Laravel's own filesystem
     * helpers, which is what a queued job on a fresh container needs.
     */
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
        return $this->readers($usePathEnv)->read($pfxContents, $password);
    }

    /**
     * A reader honouring a per-call override.
     *
     * `Signet::certificateReader()` takes no arguments, since the standalone
     * package configures the choice once. Here the override is part of the
     * published contract, so the factory is built rather than the accessor
     * used, with the configured values as its defaults.
     */
    private function readers(?bool $usePathEnv): CertificateReader
    {
        return new ReaderFactory(
            new CertificateParser(),
            $this->signet->processes(),
            $this->signet->config->certificate,
            $this->signet->temp(),
        )->make(usePathEnv: $usePathEnv);
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
            return new PemCertificateReader(new CertificateParser())->read($contents, $password);
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
