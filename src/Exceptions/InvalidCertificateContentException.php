<?php

declare(strict_types=1);

namespace LSNepomuceno\LaravelA1PdfSign\Exceptions;

use Exception;
use Stringable;

class InvalidCertificateContentException extends Exception implements Stringable
{
    /**
     * @param  string  $reason  Detail from the reader, e.g. the OpenSSL error
     *                          that explains why the bundle could not be read.
     */
    public function __construct(string $reason = '', int $code = 0, ?Exception $previous = null)
    {
        $message = 'Invalid file content, accept only valid OpenSSLCertificate.';

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
