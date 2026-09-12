<?php

declare(strict_types=1);

namespace LSNepomuceno\LaravelA1PdfSign\Adapters;

use Illuminate\Contracts\Encryption\{DecryptException, EncryptException};
use Illuminate\Encryption\Encrypter as LaravelEncrypter;
use LSNepomuceno\Signet\Contracts\Encrypter;
use LSNepomuceno\Signet\Exceptions\EncryptionException;
use SensitiveParameter;
use Throwable;

/**
 * signet-pdf's encryption contract, satisfied by Laravel's own encrypter.
 *
 * **The key stays per certificate**, which is the model this package has
 * always published: `encryptCertificate()` returns the key alongside the
 * material, and an application stores the two apart. Binding the vault to
 * `APP_KEY` instead would make every bundle already in storage unreadable,
 * for no gain a rotation policy does not already cover.
 *
 * What the adapter changes is which implementation seals it.
 * `Certificates\CertificateVault` in signet-pdf defaults to
 * XChaCha20-Poly1305 under its own 32-byte key, and a Laravel application
 * auditing its encryption should be reading `illuminate/encryption` rather
 * than a scheme it has not configured. It also means material written by 2.x
 * and material written by 3.0 are the same envelope, so upgrading re-encrypts
 * nothing (docs/decisions/0039-the-core-lives-in-signet-pdf.md).
 *
 * signet-pdf reads both envelopes regardless, keyed by the key's length, so
 * an application moving to the standalone package later is not trapped by this
 * (docs/decisions/0038-the-envelope-is-versioned.md).
 */
final readonly class IlluminateEncrypter implements Encrypter
{
    /**
     * AES-128-CBC, which is what makes the key 16 bytes and the envelope the
     * one signet-pdf recognises as this package's.
     */
    public const string CIPHER = 'aes-128-cbc';

    private LaravelEncrypter $encrypter;

    /**
     * @throws EncryptionException When the key is not valid for the cipher.
     */
    public function __construct(
        #[SensitiveParameter]
        private string $key,
    ) {
        try {
            $this->encrypter = new LaravelEncrypter($key, self::CIPHER);
        } catch (Throwable $exception) {
            throw new EncryptionException($exception->getMessage());
        }
    }

    /**
     * A vault-sized key from Laravel's own generator.
     */
    public static function generate(): self
    {
        return new self(LaravelEncrypter::generateKey(self::CIPHER));
    }

    /**
     * @throws EncryptionException
     */
    #[\Override]
    public function encryptString(#[SensitiveParameter] string $value): string
    {
        try {
            return $this->encrypter->encryptString($value);
        } catch (EncryptException $exception) {
            throw new EncryptionException($exception->getMessage());
        }
    }

    /**
     * @throws EncryptionException
     */
    #[\Override]
    public function decryptString(string $payload): string
    {
        try {
            return $this->encrypter->decryptString($payload);
        } catch (DecryptException $exception) {
            // The message is Laravel's, and it is deliberately vague: "the MAC
            // is invalid" covers a wrong key, a truncated payload and a forged
            // one alike, which is the right amount to say to a caller.
            throw new EncryptionException($exception->getMessage());
        }
    }

    #[\Override]
    public function key(): string
    {
        return $this->key;
    }
}
