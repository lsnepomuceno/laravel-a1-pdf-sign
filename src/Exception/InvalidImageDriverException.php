<?php

namespace LSNepomuceno\LaravelA1PdfSign\Exception;

use Exception;

class InvalidImageDriverException extends Exception
{
  /**
   * __construct
   *
   * @param string $driver
   * @param  int $code
   * @param  \Exception $previous
   * @return void
   */
  public function __construct(string $driver, int $code = 0, Exception $previous = null)
  {
    $message = "Error: Invalid image driver, use avaliable: \"SealImage::IMAGE_DRIVER_GD\" or \"SealImage::IMAGE_DRIVER_IMAGICK\". Current driver: {$driver}";
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
