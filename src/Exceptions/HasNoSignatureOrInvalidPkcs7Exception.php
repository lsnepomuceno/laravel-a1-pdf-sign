<?php

declare(strict_types=1);

namespace LSNepomuceno\LaravelA1PdfSign\Exceptions;

use Exception;
use Stringable;

class HasNoSignatureOrInvalidPkcs7Exception extends Exception implements Stringable
{
    public string $currentFile;

    public function __construct(string $currentFile, int $code = 0, ?Exception $previous = null)
    {
        $this->currentFile = $currentFile;
        $message           = 'The file is unsigned or the signature is not compatible with the PKCS7 type.';
        parent::__construct($message, $code, $previous);
    }

    public function __toString(): string
    {
        return __CLASS__ . ": [{$this->getCode()}]: {$this->getMessage()}\n";
    }
}
