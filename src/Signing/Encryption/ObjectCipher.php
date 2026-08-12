<?php

namespace LSNepomuceno\LaravelA1PdfSign\Signing\Encryption;

use LSNepomuceno\LaravelA1PdfSign\Exceptions\InvalidPdfFileException;
use LSNepomuceno\LaravelA1PdfSign\Signing\Incremental\DocumentInfo;

/**
 * Encrypts what a revision writes, or leaves it alone.
 *
 * A null object rather than a branch at every call site: an ordinary document
 * gets an inactive cipher and every writer emits the same code either way.
 * The alternative was `if ($encrypted)` around each of nine places, which is
 * nine chances to forget one, and a forgotten one is a stream no reader can
 * decode sitting inside an otherwise valid signature.
 *
 * @internal
 */
final readonly class ObjectCipher
{
    public function __construct(private ?StandardSecurityHandler $security = null) {}

    /**
     * The cipher a document calls for, which for most documents is none.
     */
    public static function for(DocumentInfo $document): self
    {
        return new self($document->security);
    }

    public function isActive(): bool
    {
        return $this->security !== null;
    }

    /**
     * A string as it goes into a dictionary, already delimited.
     *
     * Encrypted strings are written in hex. A literal would work and would mean
     * escaping ciphertext that contains parentheses and backslashes by
     * coincidence, so hex is the form with no escaping question in it.
     *
     * @throws InvalidPdfFileException
     */
    public function text(string $value, int $object): string
    {
        $encoded = self::textString($value);

        if ($this->security === null) {
            // Hex for anything that had to be re-encoded, for the same reason
            // ciphertext is written in hex: UTF-16BE produces 0x28 and 0x29 in
            // the middle of characters, and escaping them would be escaping
            // half a character.
            return $encoded === $value
                ? '(' . str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $value) . ')'
                : '<' . bin2hex($encoded) . '>';
        }

        // Encoded before encryption, not after. A reader decrypts and then
        // interprets the plaintext as a text string, so the byte order mark has
        // to be inside the ciphertext.
        return '<' . bin2hex($this->security->encrypt($encoded, $object)) . '>';
    }

    /**
     * The bytes of a PDF text string.
     *
     * *ISO 32000-1 §7.9.2.2, Table 35: a text string is PDFDocEncoding, or
     * UTF-16BE with a leading byte order mark.* Raw UTF-8 is neither, and it
     * was what this package wrote. A conforming reader finds no mark, decodes
     * as PDFDocEncoding, and shows the two bytes of `ã` as two characters:
     * `João` displayed as `JoÃ£o` for anybody signing in Portuguese, in a file
     * that verified perfectly.
     *
     * ASCII is PDFDocEncoding byte for byte, so it stays a literal and the
     * output for a document with an unaccented signer is unchanged. Everything
     * else is converted, which is the only branch that costs anything.
     */
    private static function textString(string $value): string
    {
        if (preg_match('/^[\x09\x0A\x0D\x20-\x7E]*$/', $value) === 1) {
            return $value;
        }

        return "\xFE\xFF" . mb_convert_encoding($value, 'UTF-16BE', 'UTF-8');
    }

    /**
     * A stream's bytes, ready to write, with the length the dictionary must
     * declare.
     *
     * The length is the encrypted length, which is why this returns both:
     * writing the plaintext length beside ciphertext is the mistake that makes
     * a file look fine until something reads past the stream.
     *
     * @return array{0: string, 1: int}
     *
     * @throws InvalidPdfFileException
     */
    public function stream(string $bytes, int $object): array
    {
        $written = $this->security === null ? $bytes : $this->security->encrypt($bytes, $object);

        return [$written, strlen($written)];
    }
}
