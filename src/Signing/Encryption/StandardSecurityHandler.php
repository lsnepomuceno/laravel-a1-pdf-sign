<?php

namespace LSNepomuceno\LaravelA1PdfSign\Signing\Encryption;

use LSNepomuceno\LaravelA1PdfSign\Exceptions\InvalidPdfFileException;

/**
 * The file encryption key, derived from a password the caller supplied.
 *
 * ISO 32000-1 §7.6.4.3 for revisions up to 4, and ISO 32000-2 §7.6.4.3.3
 * (algorithms 2.A and 2.B) for revisions 5 and 6. Once the key is in hand every
 * string and stream in the file uses it, and the revision this package appends
 * has to use it too, or the document comes back half readable.
 *
 * **The password is checked, not assumed.** A wrong one derives a key that
 * decrypts to noise, and noise appended beside a document is exactly the
 * silent corruption [0014](../../../docs/decisions/0014-refuse-encrypted-documents.md)
 * refused to produce.
 *
 * @internal
 */
final readonly class StandardSecurityHandler
{
    /**
     * §7.6.4.3, the 32-byte string a password is padded with.
     */
    private const string PADDING = "\x28\xBF\x4E\x5E\x4E\x75\x8A\x41\x64\x00\x4E\x56\xFF\xFA\x01\x08"
        . "\x2E\x2E\x00\xB6\xD0\x68\x3E\x80\x2F\x0C\xA9\xFE\x64\x53\x69\x7A";

    private function __construct(
        public EncryptionDictionary $dictionary,
        public string $key,
    ) {}

    /**
     * Derives the key, or says why it could not.
     *
     * @param  string  $firstId  The first element of the trailer's /ID, which
     *                           revisions up to 4 mix into the key. Revision 5
     *                           and above do not use it.
     *
     * @throws InvalidPdfFileException
     */
    public static function open(
        EncryptionDictionary $dictionary,
        #[\SensitiveParameter]
        string $password,
        string $firstId = '',
    ): self {
        if (! $dictionary->isSupported()) {
            throw new InvalidPdfFileException($dictionary->refusal());
        }

        $key = $dictionary->revision >= 5
            ? self::modernKey($dictionary, $password)
            : self::legacyKey($dictionary, $password, $firstId);

        if ($key === null) {
            throw new InvalidPdfFileException(
                'the password does not open this document; signing it would append a revision nothing can decrypt',
            );
        }

        return new self($dictionary, $key);
    }

    /**
     * The key for one object, §7.6.3.1.
     *
     * Revision 5 and above use the file key unchanged. Below that, each object
     * gets its own key mixed from its number and generation, which is why the
     * same string in two objects encrypts differently.
     */
    public function objectKey(int $object, int $generation = 0): string
    {
        if ($this->dictionary->algorithm->usesFileKeyDirectly()) {
            return $this->key;
        }

        $extended = $this->key
            . substr(pack('V', $object), 0, 3)
            . substr(pack('V', $generation), 0, 2)
            // §7.6.3.1: AES adds these four bytes, and nothing else does.
            . "\x73\x41\x6C\x54";

        return substr(md5($extended, true), 0, min(strlen($this->key) + 5, 16));
    }

    /**
     * Encrypts one string or stream, AES-CBC with the initialisation vector in
     * front of the ciphertext, §7.6.2.
     *
     * @throws InvalidPdfFileException
     */
    public function encrypt(string $plain, int $object, int $generation = 0): string
    {
        $cipher = $this->dictionary->algorithm->cipher();

        if ($cipher === null) {
            throw new InvalidPdfFileException($this->dictionary->refusal());
        }

        $iv = random_bytes(16);
        $encrypted = openssl_encrypt($plain, $cipher, $this->objectKey($object, $generation), OPENSSL_RAW_DATA, $iv);

        if ($encrypted === false) {
            throw new InvalidPdfFileException('the document could not be re-encrypted for the appended revision');
        }

        return $iv . $encrypted;
    }

    /**
     * The reverse, for reading a stream the document already carries.
     */
    public function decrypt(string $encrypted, int $object, int $generation = 0): ?string
    {
        $cipher = $this->dictionary->algorithm->cipher();

        if ($cipher === null || strlen($encrypted) <= 16) {
            return null;
        }

        $plain = openssl_decrypt(
            substr($encrypted, 16),
            $cipher,
            $this->objectKey($object, $generation),
            OPENSSL_RAW_DATA,
            substr($encrypted, 0, 16),
        );

        return $plain === false ? null : $plain;
    }

    /**
     * Algorithm 2.A: revision 5 and 6, where the key is stored encrypted under
     * a hash of the password rather than derived from it.
     */
    private static function modernKey(
        EncryptionDictionary $dictionary,
        #[\SensitiveParameter]
        string $password,
    ): ?string {
        foreach ([[$dictionary->user, $dictionary->userKey, ''], [$dictionary->owner, $dictionary->ownerKey, substr($dictionary->user, 0, 48)]] as [$check, $wrapped, $extra]) {
            if (strlen($check) < 48 || strlen($wrapped) < 32) {
                continue;
            }

            $hash = self::hash($dictionary->revision, $password, substr($check, 32, 8), $extra);

            if (! hash_equals(substr($check, 0, 32), $hash)) {
                continue;
            }

            $intermediate = self::hash($dictionary->revision, $password, substr($check, 40, 8), $extra);

            // The file key is stored under the intermediate key with a zero
            // vector and no padding, which is the one place AES is used here
            // without an IV in front of it.
            $key = openssl_decrypt(
                substr($wrapped, 0, 32),
                'aes-256-cbc',
                $intermediate,
                OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING,
                str_repeat("\0", 16),
            );

            if ($key !== false) {
                return $key;
            }
        }

        return null;
    }

    /**
     * Algorithm 2.B, the hardened hash revision 6 introduced.
     *
     * Revision 5 was a single SHA-256 and was withdrawn for being too cheap to
     * attack; 6 loops until the work is unpredictable. Both are kept because a
     * document written under 5 still opens.
     */
    private static function hash(
        int $revision,
        #[\SensitiveParameter]
        string $password,
        string $salt,
        string $extra,
    ): string {
        $hash = hash('sha256', $password . $salt . $extra, true);

        if ($revision === 5) {
            return $hash;
        }

        $round = 0;

        while (true) {
            $block = str_repeat($password . $hash . $extra, 64);

            $encrypted = openssl_encrypt(
                $block,
                'aes-128-cbc',
                substr($hash, 0, 16),
                OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING,
                substr($hash, 16, 16),
            );

            if ($encrypted === false) {
                return $hash;
            }

            $sum = 0;

            for ($index = 0; $index < 16; $index++) {
                $sum += ord($encrypted[$index]);
            }

            $hash = hash(match ($sum % 3) {
                0 => 'sha256',
                1 => 'sha384',
                default => 'sha512',
            }, $encrypted, true);

            $round++;

            // The loop runs at least 64 times and then until the last byte of
            // the round's ciphertext says it may stop.
            if ($round >= 64 && ord($encrypted[strlen($encrypted) - 1]) <= $round - 32) {
                return substr($hash, 0, 32);
            }
        }
    }

    /**
     * Algorithm 2: revisions 2 to 4, where the key is derived from the password
     * rather than stored.
     */
    private static function legacyKey(
        EncryptionDictionary $dictionary,
        #[\SensitiveParameter]
        string $password,
        string $firstId,
    ): ?string {
        foreach ([$password, ''] as $candidate) {
            $key = self::deriveLegacyKey($dictionary, $candidate, $firstId);

            if (self::confirmsLegacyPassword($dictionary, $key, $firstId)) {
                return $key;
            }
        }

        return null;
    }

    private static function deriveLegacyKey(
        EncryptionDictionary $dictionary,
        #[\SensitiveParameter]
        string $password,
        string $firstId,
    ): string {
        $padded = substr($password . self::PADDING, 0, 32);

        $input = $padded
            . substr($dictionary->owner, 0, 32)
            . pack('V', $dictionary->permissions)
            . $firstId;

        if ($dictionary->revision >= 4 && ! $dictionary->encryptsMetadata) {
            $input .= "\xFF\xFF\xFF\xFF";
        }

        $key = md5($input, true);

        if ($dictionary->revision >= 3) {
            for ($round = 0; $round < 50; $round++) {
                $key = md5(substr($key, 0, $dictionary->keyLength), true);
            }
        }

        return substr($key, 0, $dictionary->revision === 2 ? 5 : $dictionary->keyLength);
    }

    /**
     * Algorithm 6: rebuild /U from the key and see whether it matches.
     *
     * This is the only place RC4 appears in the package, and it is not
     * protecting anything: it recomputes a check value the document already
     * carries. The alternative was to trust the password and find out from a
     * corrupted file, which is the failure mode this whole class exists to
     * avoid. Content encrypted with RC4 is still refused outright.
     */
    private static function confirmsLegacyPassword(
        EncryptionDictionary $dictionary,
        string $key,
        string $firstId,
    ): bool {
        if ($dictionary->revision === 2) {
            return hash_equals(substr($dictionary->user, 0, 32), self::rc4($key, self::PADDING));
        }

        $value = md5(self::PADDING . $firstId, true);
        $value = self::rc4($key, $value);

        for ($round = 1; $round <= 19; $round++) {
            $rotated = '';

            for ($index = 0; $index < strlen($key); $index++) {
                // Masked so the range is provable rather than merely true: an
                // exclusive or of two bytes is a byte, and the analyser cannot
                // see that on its own.
                $rotated .= chr((ord($key[$index]) ^ $round) & 0xFF);
            }

            $value = self::rc4($rotated, $value);
        }

        // Only the first sixteen bytes are the check value; the rest is padding
        // the specification leaves arbitrary.
        return hash_equals(substr($dictionary->user, 0, 16), substr($value, 0, 16));
    }

    /**
     * RC4, written out because OpenSSL 3 moved it to the legacy provider and a
     * password check should not depend on how the host configured that.
     */
    private static function rc4(string $key, string $data): string
    {
        $state = range(0, 255);
        $swap = 0;

        for ($index = 0; $index < 256; $index++) {
            $swap = ($swap + $state[$index] + ord($key[$index % strlen($key)])) % 256;
            [$state[$index], $state[$swap]] = [$state[$swap], $state[$index]];
        }

        $out = '';
        $first = 0;
        $second = 0;

        for ($index = 0; $index < strlen($data); $index++) {
            $first = ($first + 1) % 256;
            $second = ($second + $state[$first]) % 256;
            [$state[$first], $state[$second]] = [$state[$second], $state[$first]];

            $out .= chr((ord($data[$index]) ^ $state[($state[$first] + $state[$second]) % 256]) & 0xFF);
        }

        return $out;
    }
}
