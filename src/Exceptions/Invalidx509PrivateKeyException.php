<?php

namespace LSNepomuceno\LaravelA1PdfSign\Exceptions;

use Exception;
use LSNepomuceno\LaravelA1PdfSign\Contracts\ToString;

class InvalidX509PrivateKeyException extends Exception implements ToString
{
    public function __construct(int $code = 0, Exception $previous = null)
    {
        $message = 'Invalid private key for the certificate, check that the file was generated correctly.';
        parent::__construct($message, $code, $previous);
    }

    public function __toString(): string
    {
        return __CLASS__ . ": [{$this->code}]: {$this->message}\n";
    }
}
