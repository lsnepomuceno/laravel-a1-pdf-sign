<?php

namespace LSNepomuceno\LaravelA1PdfSign\Exception;

use Exception;

class Invalidx509PrivateKeyException extends Exception
{
  /**
   * __construct
   *
   * @param  int $code 0
   * @param  \Exception $previous null
   * @return void
   */
  public function __construct(int $code = 0, Exception $previous = null)
  {
    $message = 'Invalid private key for the certificate, check that the file was generated correctly.';
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
