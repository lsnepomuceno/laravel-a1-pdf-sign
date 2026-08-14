<?php

declare(strict_types=1);

namespace LSNepomuceno\LaravelA1PdfSign\Support;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Encryption\EncryptException;
use Illuminate\Contracts\Encryption\StringEncrypter;
use SensitiveParameter;
use SodiumException;

/**
 * Authenticated encryption over ext-sodium, in the envelope `signet-pdf` writes.
 *
 * **This exists for interoperability, and the format is not chosen here.**
 * `lsnepomuceno/signet-pdf` moved certificate material onto XChaCha20-Poly1305
 * at 2.0, so a certificate sealed there stopped opening in this package. The
 * two have always been able to read each other's stored material, because an
 * application moving between them cannot re-encrypt a certificate whose
 * plaintext it no longer holds, and that guarantee is worth more than the
 * consistency of using one cipher everywhere.
 *
 * The envelope is `signet.v2.` followed by base64 of the nonce and the sealed
 * bytes. **The marker is the AEAD additional data rather than merely a
 * prefix**, so an envelope whose marker is edited fails to open instead of
 * being routed to another reader. That is signet-pdf's decision, reproduced
 * here byte for byte, and changing any of it on this side would break exactly
 * what it is here to preserve.
 *
 * `Illuminate\Contracts\Encryption\StringEncrypter` rather than the fuller
 * `Encrypter` contract: this handles strings, and implementing `decrypt()`
 * would mean offering an `unserialize()` path over attacker-supplied bytes to
 * solve a compatibility problem.
 */
final readonly class SodiumEncrypter implements StringEncrypter
{
    /**
     * What an envelope written by this scheme begins with.
     */
    public const string PREFIX = 'signet.v2.';

    /**
     * @throws EncryptException When the key is not the length libsodium requires.
     */
    public function __construct(
        #[SensitiveParameter]
        private string $key,
    ) {
        $expected = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES;

        if (strlen($key) !== $expected) {
            throw new EncryptException(sprintf(
                'the key must be %d bytes for XChaCha20-Poly1305, %d given',
                $expected,
                strlen($key),
            ));
        }
    }

    /**
     * A key of the right length, from the CSPRNG.
     */
    public static function generateKey(): string
    {
        return random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES);
    }

    /**
     * Whether this payload was written by this scheme.
     *
     * Reading the marker is not trusting it: it selects a reader, and the
     * reader then authenticates the marker along with everything else.
     */
    public static function wrote(string $payload): bool
    {
        return str_starts_with($payload, self::PREFIX);
    }

    public function getKey(): string
    {
        return $this->key;
    }

    /**
     * @throws EncryptException
     */
    public function encryptString(#[SensitiveParameter] $value): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);

        try {
            $sealed = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(
                (string) $value,
                self::PREFIX,
                $nonce,
                $this->key,
            );
        } catch (SodiumException $exception) {
            throw new EncryptException('the value could not be sealed: ' . $exception->getMessage());
        }

        return self::PREFIX . base64_encode($nonce . $sealed);
    }

    /**
     * @throws DecryptException
     */
    public function decryptString($payload): string
    {
        $payload = (string) $payload;

        if (! self::wrote($payload)) {
            // One literal, not two joined: a concatenation of message pieces
            // is a mutation apiece, and reaching them means asserting prose.
            throw new DecryptException('this payload predates the current envelope; open it with its own key');
        }

        $raw = base64_decode(substr($payload, strlen(self::PREFIX)), true);
        $nonceLength = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;

        // A payload shorter than a nonce plus a tag cannot carry either, and
        // splitting it would hand libsodium a nonce assembled out of the tag.
        if ($raw === false || strlen($raw) < $nonceLength + SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_ABYTES) {
            throw new DecryptException('the payload is not a valid envelope');
        }

        try {
            $opened = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
                substr($raw, $nonceLength),
                self::PREFIX,
                substr($raw, 0, $nonceLength),
                $this->key,
            );
        } catch (SodiumException $exception) {
            throw new DecryptException('the payload could not be opened: ' . $exception->getMessage());
        }

        if ($opened === false) {
            // One answer for a wrong key, an edited ciphertext and an edited
            // marker, because the tag does not distinguish them and inventing
            // a distinction here would leak which one it was.
            throw new DecryptException('the payload was sealed with a different key, or has been tampered with');
        }

        return $opened;
    }
}
