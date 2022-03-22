<?php

namespace LSNepomuceno\LaravelA1PdfSign\Exception;

use Exception;

class CertificateOutputNotFounfException extends Exception
{
  /**
   * __construct
   *
   * @param  int $code
   * @param  \Exception $previous
   * @return void
   */
  public function __construct(int $code = 0, Exception $previous = null)
  {
    $message = 'The certificate output file could not be found, check that the directory permissions are correct.';
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
