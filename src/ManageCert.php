<?php

namespace LSNepomuceno\LaravelA1PdfSign;

use Illuminate\Http\UploadedFile;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\{Fluent, Str, Facades\File};
use Illuminate\Contracts\Encryption\{DecryptException, EncryptException};
use OpenSSLCertificate;
use Symfony\Component\Process\{Process, Exception\ProcessFailedException};
use LSNepomuceno\LaravelA1PdfSign\Exception\{
    CertificateOutputNotFounfException,
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
    private Encrypter $encrypter;

    /** @var OpenSSLCertificate|resource|boolean */
    private mixed $certContent;

    const CIPHER = 'aes-128-cbc';

    public function __construct()
    {
        $this->tempDir = a1TempDir();

        $this->generateHashKey()->setEncrypter();

        if (!File::exists($this->tempDir)) File::makeDirectory($this->tempDir);
    }

    public function setPreservePfx(bool $preservePfx = true): self
    {
        $this->preservePfx = $preservePfx;
        return $this;
    }

    /**
     * @throws ProcessRunTimeException
     */
    private function executeProcess(string $command): void
    {
        $process = Process::fromShellCommandline($command);
        $process->run();

        while ($process->isRunning()) ;

        if (!$process->isSuccessful()) throw new ProcessRunTimeException($process->getErrorOutput());

        $process->stop(1);
    }

    /**
     * @throws CertificateOutputNotFounfException
     * @throws FileNotFoundException
     * @throws InvalidCertificateContentException
     * @throws InvalidPFXException
     * @throws Invalidx509PrivateKeyException
     * @throws ProcessRunTimeException
     */
    public function fromPfx(string $pfxPath, string $password): self
    {
        if (!Str::of($pfxPath)->lower()->endsWith('.pfx')) throw new InvalidPFXException($pfxPath);

        if (!File::exists($pfxPath)) throw new FileNotFoundException($pfxPath);

        $this->password = $password;
        $output = a1TempDir(true, '.crt');
        $openssl = "openssl pkcs12 -in {$pfxPath} -out {$output} -nodes -password pass:{$this->password}";

        $this->executeProcess(command: $openssl);

        if (!File::exists($output)) throw new CertificateOutputNotFounfException;

        $content = File::get($output);
        $filesToBeDelete = [$output];

        !$this->preservePfx && ($filesToBeDelete[] = $pfxPath);

        File::delete($filesToBeDelete);

        return $this->setCertContent($content);
    }

    /**
     * @throws CertificateOutputNotFounfException
     * @throws FileNotFoundException
     * @throws InvalidCertificateContentException
     * @throws InvalidPFXException
     * @throws Invalidx509PrivateKeyException
     * @throws ProcessRunTimeException
     * @throws \Illuminate\Contracts\Filesystem\FileNotFoundException
     */
    public function fromUpload(UploadedFile $uploadedPfx, string $password): self
    {
        $pfxTemp = a1TempDir(true);

        if (File::exists($pfxTemp)) $pfxTemp = microtime() . $pfxTemp;

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

    public function getCert(): Fluent
    {
        return new Fluent([
            'original' => $this->originalCertContent,
            'openssl' => $this->certContent,
            'data' => $this->parsedData,
            'password' => $this->password
        ]);
    }

    public function getTempDir(): string
    {
        return $this->tempDir;
    }

    /**
     * @see ManageCert::CIPHER
     */
    public function generateHashKey(): self
    {
        $this->hashKey = Encrypter::generateKey(cipher: self::CIPHER);
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
        $this->encrypter = new Encrypter(key: $this->hashKey, cipher: self::CIPHER);
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
     * @throws CertificateOutputNotFounfException
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
            $this->executeProcess($command);
        }

        File::delete(["{$name}.key", "{$name}.crt"]);

        return $returnPathAndPass ? ["{$name}.pfx", $pass] : $this->fromPfx("{$name}.pfx", $wrongPass ? 'wrongPass' : $pass);
    }
}
