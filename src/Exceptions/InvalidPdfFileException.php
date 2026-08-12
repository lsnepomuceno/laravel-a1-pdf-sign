<?php

declare(strict_types=1);

namespace LSNepomuceno\LaravelA1PdfSign\Exceptions;

use Exception;
use Stringable;

/**
 * A PDF that cannot be read, or is not a PDF at all.
 *
 * The message is the caller's, not this class's. It used to be built here, as
 * "Invalid file extension...", which was true of one of the sixteen call sites
 * and false of the other fifteen: a structural fault reported itself as a
 * filename problem. See docs/decisions/0008-exceptions-name-the-real-fault.md.
 */
class InvalidPdfFileException extends Exception implements A1PdfSignException, Stringable
{
    public function __construct(string $message, int $code = 0, ?Exception $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }

    /**
     * The one case the old wording described, kept byte for byte.
     */
    public static function extension(string $currentFile): self
    {
        return new self("Invalid file extension, accept only \".pdf\" extension files. Current file: {$currentFile}.");
    }

    public function __toString(): string
    {
        return __CLASS__ . ": [{$this->getCode()}]: {$this->getMessage()}\n";
    }
}
