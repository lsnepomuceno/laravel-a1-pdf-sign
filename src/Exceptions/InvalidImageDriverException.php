<?php

namespace LSNepomuceno\LaravelA1PdfSign\Exceptions;

use Exception;
use LSNepomuceno\LaravelA1PdfSign\Contracts\Stringable;

class InvalidImageDriverException extends Exception implements Stringable
{
    public function __construct(string $driver, int $code = 0, Exception $previous = null)
    {
        $message = "Error: Invalid image driver, use available: \"SealImage::IMAGE_DRIVER_GD\" or \"SealImage::IMAGE_DRIVER_IMAGICK\". Current driver: {$driver}";
        parent::__construct($message, $code, $previous);
    }

    public function __toString(): string
    {
        return __CLASS__ . ": [{$this->code}]: {$this->message}\n";
    }
}
