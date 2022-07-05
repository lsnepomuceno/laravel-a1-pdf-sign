<?php

namespace LSNepomuceno\LaravelA1PdfSign\Exceptions;

use Exception;
use LSNepomuceno\LaravelA1PdfSign\Contracts\Stringable;

class CertificateOutputNotFoundException extends Exception implements Stringable
{
    public function __construct(int $code = 0, Exception $previous = null)
    {
        $message = 'The certificate output file could not be found, check that the directory permissions are correct.';
        parent::__construct($message, $code, $previous);
    }


    public function __toString(): string
    {
        return __CLASS__ . ": [{$this->code}]: {$this->message}\n";
    }
}
