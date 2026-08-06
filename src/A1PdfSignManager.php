<?php

namespace LSNepomuceno\LaravelA1PdfSign;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use LSNepomuceno\LaravelA1PdfSign\Certificates\CertificateParser;
use LSNepomuceno\LaravelA1PdfSign\Contracts\A1PdfSign;
use LSNepomuceno\LaravelA1PdfSign\Contracts\SignatureValidator;
use LSNepomuceno\LaravelA1PdfSign\Data\EncryptedCertificate;
use LSNepomuceno\LaravelA1PdfSign\Data\SignatureReport;
use LSNepomuceno\LaravelA1PdfSign\Enums\SignatureMode;
use LSNepomuceno\LaravelA1PdfSign\Sign\ManageCert;
use LSNepomuceno\LaravelA1PdfSign\Signing\PendingSignature;
use SensitiveParameter;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Default implementation of the package's entry point.
 *
 * Every argument that the v1 helpers took as a required boolean is nullable
 * here: null means "use the configured default", so callers stop repeating
 * infrastructure decisions at every call site.
 */
final readonly class A1PdfSignManager implements A1PdfSign
{
    public function __construct(
        private Config $config,
        private CertificateParser $parser,
        private Container $container,
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
        $cert = $this->certificate()->fromPfx($pfxPath, $password, $this->usePathEnv($usePathEnv));

        return $this->sign($pdfPath, $cert, $mode);
    }

    public function signFromUpload(
        UploadedFile $uploadedPfx,
        #[SensitiveParameter]
        string $password,
        string $pdfPath,
        SignatureMode|string|null $mode = null,
        ?bool $usePathEnv = null,
    ): BinaryFileResponse|string {
        $cert = $this->certificate()->fromUpload($uploadedPfx, $password, $this->usePathEnv($usePathEnv));

        return $this->sign($pdfPath, $cert, $mode);
    }

    public function encryptCertificate(
        UploadedFile|string $uploadedOrPfxPath,
        #[SensitiveParameter]
        string $password,
        ?bool $usePathEnv = null,
    ): EncryptedCertificate {
        $cert = $this->certificate();
        $usePathEnv = $this->usePathEnv($usePathEnv);

        $uploadedOrPfxPath instanceof UploadedFile
            ? $cert->fromUpload($uploadedOrPfxPath, $password, $usePathEnv)
            : $cert->fromPfx($uploadedOrPfxPath, $password, $usePathEnv);

        // The hash is required by decryptCertificate(); without it the pair
        // cannot be read back.
        return $cert->getVault()->seal($cert->getCert(), $password);
    }

    /**
     * encryptCertificate() stores the PEM bundle, so this parses it directly.
     * The v1 helper wrote it to a .pfx and fed it back to `openssl pkcs12 -in`,
     * which expects binary PKCS#12 and always failed — see
     * ARCHITECTURE-V2.md §1.14.
     */
    public function decryptCertificate(
        #[SensitiveParameter]
        string $hashKey,
        string $encryptedCertificate,
        #[SensitiveParameter]
        string $password,
        bool $isBase64 = false,
        ?bool $usePathEnv = null,
    ): ManageCert {
        $cert = $this->certificate()->setHashKey($hashKey);

        $restored = $cert->getVault()->open(
            $this->parser,
            $encryptedCertificate,
            $password,
            $isBase64,
        );

        return $cert->setCertContent($restored->original, $restored->password);
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
            : $this->defaultTempPath();

        if (!File::isDirectory($path)) {
            File::makeDirectory($path, recursive: true);
        }

        return $tempFile ? $path . Str::orderedUuid() . $fileExt : $path;
    }

    /**
     * A certificate reader carrying the configured legacy setting.
     */
    private function certificate(): ManageCert
    {
        return (new ManageCert())
            ->setIsLegacy((bool) $this->config->get('a1-pdf-sign.certificate.legacy', false));
    }

    /**
     * Signing produces bytes; the mode only picks how they are handed back.
     * The v1 flow wrote them to disk and read them straight back for the
     * bytes case (§1.8).
     */
    private function sign(
        string $pdfPath,
        ManageCert $cert,
        SignatureMode|string|null $mode,
    ): BinaryFileResponse|string {
        $signed = $this->newSignature()
            ->usingCertificate($cert->getCert())
            ->pdf($pdfPath)
            ->sign();

        return SignatureMode::resolve($mode ?? SignatureMode::Resource) === SignatureMode::Download
            ? $signed->download()
            : $signed->contents();
    }

    private function usePathEnv(?bool $override): bool
    {
        return $override ?? (bool) $this->config->get('a1-pdf-sign.certificate.use_path_env', false);
    }

    /**
     * Historically the package wrote into src/Temp/, which required vendor/ to
     * be writable. That path is still honoured when it exists and is writable,
     * so upgrades do not move files unexpectedly, but it is no longer created.
     */
    private function defaultTempPath(): string
    {
        $packageTemp = __DIR__ . '/Temp/';

        return File::isDirectory($packageTemp) && is_writable($packageTemp)
            ? $packageTemp
            : rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    }
}
