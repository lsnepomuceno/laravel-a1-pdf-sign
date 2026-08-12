<?php

namespace LSNepomuceno\LaravelA1PdfSign\Enums;

/**
 * How a document's strings and streams are encrypted, ISO 32000-1 §7.6.
 *
 * The two AES cases are supported. RC4 is not, and that is a decision rather
 * than an omission: writing RC4 into a document in order to sign it would mean
 * this package weakening a file it was handed
 * (docs/decisions/0030-signing-a-document-that-is-encrypted.md).
 */
enum EncryptionAlgorithm: string
{
    /** AES-128 in CBC mode, the `AESV2` crypt filter, revision 4. */
    case Aes128 = 'aesv2';

    /** AES-256 in CBC mode, the `AESV3` crypt filter, revision 5 and 6. */
    case Aes256 = 'aesv3';

    /** RC4 at any key length, which this package reads about and refuses. */
    case Rc4 = 'rc4';

    /** The `Identity` filter: this stream or string is not encrypted at all. */
    case None = 'identity';

    /**
     * The name a crypt filter's `/CFM` entry uses.
     */
    public static function fromCryptFilterMethod(string $method): self
    {
        return match ($method) {
            'AESV2' => self::Aes128,
            'AESV3' => self::Aes256,
            'V2' => self::Rc4,
            default => self::None,
        };
    }

    /**
     * Whether this package can read and write a document using it.
     */
    public function isSupported(): bool
    {
        return $this === self::Aes128 || $this === self::Aes256;
    }

    /**
     * The OpenSSL cipher, for the two that have one here.
     */
    public function cipher(): ?string
    {
        return match ($this) {
            self::Aes128 => 'aes-128-cbc',
            self::Aes256 => 'aes-256-cbc',
            default => null,
        };
    }

    /**
     * Whether the file encryption key is used as it is, rather than mixed with
     * the object and generation numbers first.
     *
     * §7.6.3.1: revision 5 and above stopped deriving a key per object, which
     * is why AES-256 needs no object number at all.
     */
    public function usesFileKeyDirectly(): bool
    {
        return $this === self::Aes256;
    }
}
