<?php

declare(strict_types=1);

namespace LSNepomuceno\LaravelA1PdfSign\Exceptions;

use Exception;
use Stringable;

/**
 * A signature field that cannot be signed into.
 *
 * Each of these is deliberately an error rather than a fallback to appending a
 * new field beside the one asked for. That fallback is exactly the failure
 * intoField() exists to prevent, and it would happen quietly: a document with a
 * valid signature in the wrong place and the template's own field still empty.
 *
 * See docs/decisions/0013-signing-into-an-existing-field.md.
 */
class SignatureFieldException extends Exception implements Stringable
{
    /**
     * @param  list<string>  $available  Named so the caller can see the spelling
     *                                   they meant, which is the usual cause.
     */
    public static function missing(string $name, array $available): self
    {
        $names = $available === [] ? 'it carries none' : 'it carries ' . implode(', ', $available);

        return new self("the document has no signature field named \"{$name}\": {$names}");
    }

    public static function alreadySigned(string $name): self
    {
        return new self(
            "the signature field \"{$name}\" is already signed; filling it again would replace that signature rather than add one",
        );
    }

    /**
     * A field carries its own rectangle, so a placement passed alongside it is
     * a contradiction. One would have to win, and resolving it by precedence
     * would silently move the seal off the box the template drew.
     */
    public static function placementConflict(string $name): self
    {
        return new self(
            "a seal placement cannot be given with intoField(\"{$name}\"): the field already has a rectangle, and the seal is drawn into it",
        );
    }

    public function __toString(): string
    {
        return __CLASS__ . ": [{$this->getCode()}]: {$this->getMessage()}\n";
    }
}
