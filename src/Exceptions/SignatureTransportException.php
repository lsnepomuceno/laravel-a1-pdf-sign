<?php

declare(strict_types=1);

namespace LSNepomuceno\LaravelA1PdfSign\Exceptions;

use Exception;
use Throwable;

/**
 * A request the signature needed did not come back.
 *
 * Timestamp authorities, OCSP responders and CRL distribution points are third
 * parties over the public internet, and every profile above `pades-b-b` depends
 * on one.
 *
 * It replaces ProcessRunTimeException on this path, which named a fault that
 * did not occur: no process is run to fetch a timestamp, and a consumer
 * catching it to handle a shell-out problem was catching a network problem
 * instead (docs/decisions/0008-exceptions-name-the-real-fault.md).
 */
final class SignatureTransportException extends Exception implements A1PdfSignException
{
    public function __construct(public readonly string $url, string $detail, ?Throwable $previous = null)
    {
        parent::__construct("The request to {$url} failed: {$detail}", previous: $previous);
    }
}
