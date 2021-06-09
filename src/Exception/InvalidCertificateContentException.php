<?php

namespace LSNepomuceno\LaravelA1PdfSign\Exception;

use Exception;

class InvalidCertificateContentException extends Exception
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
    $message = 'Invalid file content, accept only valid OpenSSLCertificate.';
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
