<?php /**
   * __toString
   *
   * @return string
   */


namespace LSNepomuceno\LaravelA1PdfSign\Exceptions;

use Exception;

class FileNotFoundException extends Exception
{
  public function __construct(string $currentFile, int $code = 0, Exception $previous = null)
  {
    $message = "File not found. Current file: {$currentFile}.";
    parent::__construct($message, $code, $previous);
  }

  public function __toString(): string
  {
    return __CLASS__ . ": [{$this->code}]: {$this->message}\n";
  }
}
