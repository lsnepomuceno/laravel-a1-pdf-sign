<?php

namespace LSNepomuceno\LaravelA1PdfSign\Exceptions;

use Exception;
use LSNepomuceno\LaravelA1PdfSign\Contracts\Stringable;

class ProcessRunTimeException extends Exception implements Stringable
{
    public function __construct(string $reason, int $code = 0, Exception $previous = null)
    {
        $reason = preg_replace('/[\n\r]/m', '. ', $reason);
        $message = "Process runtime error, reason: \"{$reason}\"";
        parent::__construct($message, $code, $previous);
    }

    public function __toString(): string
    {
        return __CLASS__ . ": [{$this->code}]: {$this->message}\n";
    }
}
