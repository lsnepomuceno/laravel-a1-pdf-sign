<?php

declare(strict_types=1);

namespace LSNepomuceno\LaravelA1PdfSign\Certificates;

use Illuminate\Encryption\Encrypter;
use LSNepomuceno\LaravelA1PdfSign\Data\Certificate;
use LSNepomuceno\LaravelA1PdfSign\Data\EncryptedCertificate;
use LSNepomuceno\LaravelA1PdfSign\Exceptions\InvalidCertificateContentException;
use LSNepomuceno\LaravelA1PdfSign\Exceptions\InvalidX509PrivateKeyException;
use SensitiveParameter;

/**
 * Encrypts a parsed certificate for storage, and reads it back.
 *
 * Each vault carries its own key. seal() returns that key alongside the
 * ciphertext, and open() needs it: losing it means losing the certificate.
 */
final readonly class CertificateVault
{
    public const string CIPHER = 'aes-128-cbc';

    private function __construct(private Encrypter $encrypter) {}

    /**
     * A vault with a freshly generated key.
     */
    public static function create(): self
    {
        return new self(new Encrypter(Encrypter::generateKey(self::CIPHER), self::CIPHER));
    }

    /**
     * A vault bound to an existing key, as returned by seal().
     */
    public static function withKey(#[SensitiveParameter] string $key): self
    {
        return new self(new Encrypter($key, self::CIPHER));
    }

    public function encrypter(): Encrypter
    {
        return $this->encrypter;
    }

    public function key(): string
    {
        return $this->encrypter->getKey();
    }

    public function seal(
        Certificate $certificate,
        #[SensitiveParameter]
        string $password,
    ): EncryptedCertificate {
        return new EncryptedCertificate(
            certificate: $this->encrypter->encryptString($certificate->original),
            password: $this->encrypter->encryptString($password),
            hash: $this->key(),
        );
    }

    /**
     * Restores a sealed certificate.
     *
     * What seal() stored is the PEM bundle, so it is parsed directly: no
     * PKCS#12 conversion, no temporary file and no shell-out. The v1 pair
     * wrote the PEM to a .pfx and fed it back to `openssl pkcs12 -in`, which
     * expects binary PKCS#12 and always failed. See docs/history/v2-modernization.md.
     *
     * @throws InvalidCertificateContentException
     * @throws InvalidX509PrivateKeyException
     */
    public function open(
        CertificateParser $parser,
        string $encryptedCertificate,
        #[SensitiveParameter]
        string $encryptedPassword,
        bool $isBase64 = false,
    ): Certificate {
        $pem = $this->encrypter->decryptString($encryptedCertificate);

        if ($isBase64) {
            $decoded = base64_decode($pem, true);
            $pem = $decoded === false || $decoded === '' ? $pem : $decoded;
        }

        return $parser->parse($pem, $this->encrypter->decryptString($encryptedPassword));
    }
}
