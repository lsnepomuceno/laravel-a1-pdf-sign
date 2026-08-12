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
        if ($this->security === null) {
            return '(' . str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $value) . ')';
        }

        return '<' . bin2hex($this->security->encrypt($value, $object)) . '>';
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
