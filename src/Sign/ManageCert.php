<?php

namespace LSNepomuceno\LaravelA1PdfSign\Sign;

use Illuminate\Contracts\Encryption\{DecryptException, EncryptException};
use Illuminate\Encryption\Encrypter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\{Facades\File, Str};
use LSNepomuceno\LaravelA1PdfSign\Entities\CertificateProcessed;
use LSNepomuceno\LaravelA1PdfSign\Exceptions\{CertificateOutputNotFoundException,
    FileNotFoundException,
    InvalidCertificateContentException,
    InvalidPFXException,
    Invalidx509PrivateKeyException,
    ProcessRunTimeException
};

class ManageCert
{
    private string $tempDir, $originalCertContent, $password, $hashKey;
    private bool $preservePfx = false;
    private array $parsedData;
    private \OpenSSLCertificate|bool $certContent;
    const CIPHER = 'aes-128-cbc';
    private Encrypter $encrypter;

    public function __construct()
    {
        $this->tempDir = a1TempDir();
        $this->generateHashKey()->setEncrypter();

        if (!File::exists($this->tempDir)) {
            File::makeDirectory($this->tempDir);
        }
    }

    public function setPreservePfx(bool $preservePfx = true): self
    {
        $this->preservePfx = $preservePfx;
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
    public function fromPfx(string $pfxPath, string $password): self
    {
        if (!Str::of($pfxPath)->lower()->endsWith('.pfx')) {
            throw new InvalidPFXException($pfxPath);
        }

        if (!File::exists($pfxPath)) {
            throw new FileNotFoundException($pfxPath);
        }

        $this->password = $password;
        $output = a1TempDir(true, '.crt');
        $openSslCommand = "openssl pkcs12 -in {$pfxPath} -out {$output} -nodes -password pass:{$this->password}";

        runCliCommandProcesses($openSslCommand);

        if (!File::exists($output)) {
            throw new CertificateOutputNotFoundException;
        }

        $content = File::get($output);

        $filesToBeDelete = [$output];

        !$this->preservePfx && ($filesToBeDelete[] = $pfxPath);

        File::delete($filesToBeDelete);

        return $this->setCertContent($content);
    }

    /**
     * @throws CertificateOutputNotFoundException
     * @throws FileNotFoundException
     * @throws InvalidPFXException
     * @throws ProcessRunTimeException
     * @throws \Illuminate\Contracts\Filesystem\FileNotFoundException
     */
    public function fromUpload(UploadedFile $uploadedPfx, string $password): self
    {
        $pfxTemp = a1TempDir(true);

        if (File::exists($pfxTemp)) {
            $pfxTemp = microtime() . $pfxTemp;
        }

        File::put($pfxTemp, $uploadedPfx->get());

        $this->fromPfx($pfxTemp, $password);

        File::delete($pfxTemp);

        return $this;
    }

    /**
     * @throws InvalidCertificateContentException
     * @throws Invalidx509PrivateKeyException
     */
    public function setCertContent(string $certContent): self
    {
        $this->originalCertContent = $certContent;
        $this->certContent = openssl_x509_read(certificate: $certContent);
        $this->parsedData = openssl_x509_parse(certificate: $this->certContent, short_names: false);
        $this->validate();
        return $this;
    }

    /**
     * @throws InvalidCertificateContentException
     * @throws Invalidx509PrivateKeyException
     */
    public function validate(): void
    {
        if (!$this->certContent) {
            $this->invalidate();
            throw new InvalidCertificateContentException;
        }

        if (!openssl_x509_check_private_key(certificate: $this->certContent, private_key: $this->originalCertContent)) {
            $this->invalidate();
            throw new Invalidx509PrivateKeyException;
        }
    }

    private function invalidate(): void
    {
        $this->originalCertContent = '';
        $this->certContent = false;
        $this->parsedData = [];
        $this->password = '';
    }

    public function getCert(): CertificateProcessed
    {
        return new CertificateProcessed(
            original: $this->originalCertContent,
            openssl:  $this->certContent,
            data:     $this->parsedData,
            password: $this->password
        );
    }

    public function getTempDir(): string
    {
        return $this->tempDir;
    }

    public function generateHashKey(): self
    {
        $this->hashKey = Encrypter::generateKey(self::CIPHER);
        $this->setEncrypter();
        return $this;
    }

    public function setHashKey(string $hashKey): self
    {
        $this->hashKey = $hashKey;
        $this->setEncrypter();
        return $this;
    }

    private function setEncrypter(): void
    {
        $this->encrypter = new Encrypter($this->hashKey, self::CIPHER);
    }

    public function getHashKey(): string
    {
        return $this->encrypter->getKey();
    }

    public function getEncrypter(): Encrypter
    {
        return $this->encrypter;
    }

    /**
     * @throws EncryptException
     */
    public function encryptBase64BlobString(string $blobString): string
    {
        return $this->encrypter->encryptString(base64_encode($blobString));
    }

    /**
     * @throws DecryptException
     */
    public function decryptBase64BlobString(string $encryptedBlobString): string
    {
        $string = $this->encrypter->decryptString($encryptedBlobString);
        return base64_decode($string);
    }

    /**
     * @throws CertificateOutputNotFoundException
     * @throws FileNotFoundException
     * @throws InvalidCertificateContentException
     * @throws InvalidPFXException
     * @throws Invalidx509PrivateKeyException
     * @throws ProcessRunTimeException
     */
    public function makeDebugCertificate(bool $returnPathAndPass = false, bool $wrongPass = false): array|static
    {
        $pass = 123456;
        $name = $this->tempDir . Str::orderedUuid();

        $genCommands = [
            "openssl req -x509 -newkey rsa:4096 -sha256 -keyout {$name}.key -out {$name}.crt -subj \"/CN=Test Certificate /OU=LucasNepomuceno\" -days 600 -passout pass:{$pass}",
            "openssl pkcs12 -export -name test.com -out {$name}.pfx -inkey {$name}.key -in {$name}.crt -passin pass:{$pass} -passout pass:{$pass}"
        ];

        foreach ($genCommands as $command) {
            runCliCommandProcesses($command);
        }

        File::delete(["{$name}.key", "{$name}.crt"]);

        if ($returnPathAndPass) {
            return ["{$name}.pfx", $pass];
        }

        return $this->fromPfx("{$name}.pfx", $wrongPass ? 'wrongPass' : $pass);
    }
}
