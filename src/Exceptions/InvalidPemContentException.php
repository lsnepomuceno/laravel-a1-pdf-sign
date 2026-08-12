<?php

declare(strict_types=1);

namespace LSNepomuceno\LaravelA1PdfSign\Exceptions;

use Exception;
use Stringable;

/**
 * The input is not the PEM it was supposed to be.
 *
 * This covers only what can be decided before parsing: a missing block, or
 * binary bytes handed to the PEM entry point. A certificate and key that are
 * both present but do not belong together is a different failure, and keeps
 * its own class: {@see InvalidX509PrivateKeyException}.
 */
class InvalidPemContentException extends Exception implements A1PdfSignException, Stringable
{
    /**
     * @param  string  $reason  What is wrong with the input, e.g. which block is missing.
     */
    public function __construct(string $reason = '', int $code = 0, ?Exception $previous = null)
    {
        $message = 'Invalid PEM content.';

        if ($reason !== '') {
            $message .= " {$reason}";
        }

        parent::__construct($message, $code, $previous);
    }

    public function __toString(): string
    {
        return __CLASS__ . ": [{$this->getCode()}]: {$this->getMessage()}\n";
    }
}
