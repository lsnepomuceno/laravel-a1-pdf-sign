<?php

declare(strict_types=1);

namespace LSNepomuceno\LaravelA1PdfSign\Exceptions;

use Exception;
use Stringable;

/**
 * A seal that cannot be placed where it was asked for.
 *
 * Clamping to the nearest page would be the quiet answer, and quiet is the
 * problem: a seal asked for on page 7 of a three-page contract is a caller
 * mistake, and putting it on page 3 produces a signed document that looks
 * deliberate and is not.
 *
 * See docs/decisions/0017-the-seal-goes-where-it-was-asked-for.md.
 */
class SealPlacementException extends Exception implements A1PdfSignException, Stringable
{
    public static function pageOutOfRange(int $page, int $pageCount): self
    {
        $pages = $pageCount === 1 ? '1 page' : "{$pageCount} pages";

        return new self(
            "the seal was placed on page {$page}, but the document has {$pages}",
        );
    }

    public function __toString(): string
    {
        return __CLASS__ . ": [{$this->getCode()}]: {$this->getMessage()}\n";
    }
}
