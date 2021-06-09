<?php

namespace LSNepomuceno\LaravelA1PdfSign\Exception;

use Exception;

class InvalidPdfSignModeTypeException extends Exception
{
  /**
   * __construct
   *
   * @param string $mode
   * @param  int $code
   * @param  \Exception $previous
   * @return void
   */
  public function __construct(string $mode, int $code = 0, Exception $previous = null)
  {
    $message = "Error: Invalid mode type, use avaliable modes: \"self::MODE_RESOURCE\" or \"self::MODE_DOWNLOAD\". Current mode: {$mode}";
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
