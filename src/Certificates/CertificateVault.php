<?php

declare(strict_types=1);

namespace LSNepomuceno\LaravelA1PdfSign\Certificates;

use Illuminate\Contracts\Encryption\StringEncrypter;
use Illuminate\Encryption\Encrypter;
use LSNepomuceno\LaravelA1PdfSign\Data\Certificate;
use LSNepomuceno\LaravelA1PdfSign\Data\EncryptedCertificate;
use LSNepomuceno\LaravelA1PdfSign\Exceptions\InvalidCertificateContentException;
use LSNepomuceno\LaravelA1PdfSign\Exceptions\InvalidX509PrivateKeyException;
use LSNepomuceno\LaravelA1PdfSign\Support\SodiumEncrypter;
use SensitiveParameter;

/**
 * Encrypts a parsed certificate for storage, and reads it back.
 *
 * Each vault carries its own key. seal() returns that key alongside the
 * ciphertext, and open() needs it: losing it means losing the certificate.
 *
 * **Two envelopes are readable, and the key's length says which.** Everything
 * sealed by this package carries Laravel's, under a 16-byte AES-128-CBC key.
 * `lsnepomuceno/signet-pdf` moved to XChaCha20-Poly1305 under a 32-byte key at
 * its 2.0, and material sealed there has to open here: an application moving
 * between the two cannot re-encrypt a certificate whose plaintext it no longer
 * holds. Those are the only two lengths either package has issued, so the
 * mapping is total (docs/decisions/0038-the-envelope-is-versioned.md).
 */
final readonly class CertificateVault
{
    public const string CIPHER = 'aes-128-cbc';

    /**
     * What `self::CIPHER` requires, and therefore what a key written by this
     * package measures.
     */
    public const int KEY_LENGTH = 16;

    /**
     * The key is held here rather than read back off the encrypter, because
     * `StringEncrypter` does not expose one and asking for the fuller contract
     * would mean implementing an `unserialize()` path to satisfy it.
     */
    private function __construct(
        private StringEncrypter $encrypter,
        #[SensitiveParameter]
        private string $key,
    ) {}

    /**
     * A vault with a freshly generated key.
     */
    public static function create(): self
    {
        $key = Encrypter::generateKey(self::CIPHER);

        return new self(new Encrypter($key, self::CIPHER), $key);
    }

    /**
     * A vault bound to an existing key, as returned by seal().
     *
     * The length chooses the envelope: 16 bytes is this package's own, and 32
     * is the one `signet-pdf` writes. A key of any other length belongs to
     * neither and says so, rather than being padded into one of them.
     *
     * @throws InvalidCertificateContentException When the key is the wrong length.
     */
    public static function withKey(#[SensitiveParameter] string $key): self
    {
        return new self(
            match (strlen($key)) {
                SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES => new SodiumEncrypter($key),
                self::KEY_LENGTH => new Encrypter($key, self::CIPHER),
                // sprintf rather than five concatenations, and not for taste:
                // each join is its own mutation, so a message assembled in
                // pieces generates a pile of them that no honest test kills.
                // Asserting the exact prose to reach them would pin the wording
                // instead of the behaviour, which is worse than leaving them
                // alive.
                default => throw new InvalidCertificateContentException(sprintf(
                    'the key must be %d bytes, or %d for material sealed by signet-pdf, %d given',
                    self::KEY_LENGTH,
                    SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES,
                    strlen($key),
                )),
            },
            $key,
        );
    }

    public function encrypter(): StringEncrypter
    {
        return $this->encrypter;
    }

    public function key(): string
    {
        return $this->key;
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
