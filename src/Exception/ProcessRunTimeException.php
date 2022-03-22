<?php

namespace LSNepomuceno\LaravelA1PdfSign\Exception;

use Exception;

class ProcessRunTimeException extends Exception
{
  /**
   * __construct
   *
   * @param  string $reason
   * @param  int $code 0
   * @param  \Exception $previous null
   * @return void
   */
  public function __construct(string $reason, int $code = 0, Exception $previous = null)
  {
    $reason  = preg_replace('/\n|\r/m', '. ', $reason);
    $message = "Process runtime error, reason: \"{$reason}\"";
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
