<?php

declare(strict_types=1);

namespace LSNepomuceno\LaravelA1PdfSign\Exceptions;

use Exception;
use Stringable;

/**
 * A field that an earlier signature locked, or a lock that locks nothing.
 *
 * Both are refusals rather than warnings. Filling a locked field produces a
 * document whose earlier signature a reader reports as broken, and the caller
 * finds out from the reader rather than from here.
 *
 * See docs/decisions/0021-locking-fields-and-honouring-locks.md.
 */
class FieldLockException extends Exception implements Stringable
{
    public static function locked(string $field, string $by): self
    {
        return new self(
            "the field \"{$field}\" was locked by the signature in \"{$by}\"; filling it would break that signature",
        );
    }

    /**
     * An /Include action with no fields locks nothing, and an /Exclude action
     * with no fields locks everything. Neither is plausibly what was meant, and
     * the second is the more expensive to discover.
     */
    public static function needsFields(string $action): self
    {
        return new self(
            "a \"{$action}\" field lock needs the fields it applies to; pass them, or use FieldLock::all()",
        );
    }

    public function __toString(): string
    {
        return __CLASS__ . ": [{$this->getCode()}]: {$this->getMessage()}\n";
    }
}
