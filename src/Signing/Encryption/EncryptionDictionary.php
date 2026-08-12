<?php

declare(strict_types=1);

namespace LSNepomuceno\LaravelA1PdfSign\Signing\Encryption;

use LSNepomuceno\LaravelA1PdfSign\Enums\EncryptionAlgorithm;

/**
 * The `/Encrypt` dictionary, as the standard security handler writes it.
 *
 * ISO 32000-1 §7.6.4. Only the standard handler is modelled: a document using a
 * different one is refused rather than guessed at, since a handler is by
 * definition something this package does not know how to key.
 *
 * @internal
 */
final readonly class EncryptionDictionary
{
    /**
     * @param  string  $owner  /O, the owner password's check value.
     * @param  string  $user  /U, the user password's check value.
     * @param  string  $ownerKey  /OE, present from revision 5.
     * @param  string  $userKey  /UE, present from revision 5.
     * @param  int  $permissions  /P, a signed 32-bit integer.
     * @param  int  $keyLength  In bytes.
     */
    public function __construct(
        public int $version,
        public int $revision,
        public string $owner,
        public string $user,
        public string $ownerKey,
        public string $userKey,
        public int $permissions,
        public int $keyLength,
        public EncryptionAlgorithm $algorithm,
        public bool $encryptsMetadata = true,
        public bool $isStandardHandler = true,
    ) {}

    /**
     * Reads the dictionary out of the raw object it lives in.
     *
     * Hex strings only, which is what every producer writes for these values
     * and what the fields are: an /O written as a literal string would carry
     * escapes the reader here would have to unpick, and no producer does it.
     */
    public static function parse(string $object): self
    {
        $revision = (int) self::number($object, 'R');
        $version = (int) self::number($object, 'V');

        return new self(
            version: $version,
            revision: $revision,
            owner: self::hex($object, 'O'),
            user: self::hex($object, 'U'),
            ownerKey: self::hex($object, 'OE'),
            userKey: self::hex($object, 'UE'),
            // /P is written as a signed value and PHP reads it as one; what
            // matters is the four bytes it makes, which the handler takes
            // little-endian.
            permissions: (int) self::number($object, 'P'),
            keyLength: self::keyLength($object, $algorithm = self::algorithm($object, $version)),
            algorithm: $algorithm,
            encryptsMetadata: ! str_contains($object, '/EncryptMetadata false'),
            isStandardHandler: preg_match('#/Filter\s*/Standard\b#', $object) === 1,
        );
    }

    /**
     * Whether this package can sign a document encrypted like this.
     */
    public function isSupported(): bool
    {
        return $this->isStandardHandler && $this->algorithm->isSupported();
    }

    /**
     * Why not, in words a caller can act on.
     */
    public function refusal(): string
    {
        if (! $this->isStandardHandler) {
            return 'the document uses a security handler other than the standard one, which needs a key this package cannot derive';
        }

        return $this->algorithm === EncryptionAlgorithm::Rc4
            ? 'the document is encrypted with RC4, and signing it would mean writing RC4 back into it'
            : 'the document uses an encryption scheme this package does not implement';
    }

    /**
     * §7.6.4.4: the crypt filter named by /StmF and /StrF decides the method,
     * and versions below 4 have no filters at all and are always RC4.
     */
    private static function algorithm(string $object, int $version): EncryptionAlgorithm
    {
        if ($version < 4) {
            return EncryptionAlgorithm::Rc4;
        }

        return preg_match('#/CFM\s*/(\w+)#', $object, $found) === 1
            ? EncryptionAlgorithm::fromCryptFilterMethod($found[1])
            : EncryptionAlgorithm::None;
    }

    /**
     * The key length in bytes, taken from the algorithm rather than from
     * `/Length`.
     *
     * `/Length` is genuinely ambiguous in a document with crypt filters: the
     * dictionary's own is in bits, the one inside `/CF` is in bytes, and the
     * second is written first, so reading the first match gives 16 where 128
     * was meant. The algorithm settles it without a heuristic: AESV2 is
     * AES-128 by definition and AESV3 is AES-256 by definition. Only RC4 has a
     * length worth reading, and that is refused anyway.
     */
    private static function keyLength(string $object, EncryptionAlgorithm $algorithm): int
    {
        return match ($algorithm) {
            EncryptionAlgorithm::Aes128 => 16,
            EncryptionAlgorithm::Aes256 => 32,
            default => max(5, intdiv((int) (self::number($object, 'Length') ?? 40), 8)),
        };
    }

    private static function number(string $object, string $key): ?string
    {
        return preg_match('#/' . $key . '\s+(-?\d+)#', $object, $found) === 1 ? $found[1] : null;
    }

    private static function hex(string $object, string $key): string
    {
        if (preg_match('#/' . $key . '\s*<([0-9a-fA-F\s]*)>#', $object, $found) !== 1) {
            return '';
        }

        return (string) hex2bin((string) preg_replace('/\s+/', '', $found[1]));
    }
}
