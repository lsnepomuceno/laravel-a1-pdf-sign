<?php

namespace LSNepomuceno\LaravelA1PdfSign\Exception;

use Exception;

class HasNoSignatureOrInvalidPkcs7Exception extends Exception
{
  /**
   * @var string
   */
  public string $currentFile;

  /**
   * __construct
   *
   * @param  int $code 0
   * @param  \Exception $previous null
   * @return void
   */
  public function __construct(string $currentFile, int $code = 0, Exception $previous = null)
  {
    $this->currentFile = $currentFile;
    $message = 'The file is unsigned or the signature is not compatible with the PKCS7 type.';
    parent::__construct($message, $code, $previous);
  }

  /**
   * __toString
   *
   * @return string
   */
  public function __toString(): string
  {
    return __CLASS__ . ": [{$this->code}]: {$this->message}\n";
  }
}
