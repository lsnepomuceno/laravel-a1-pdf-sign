<?php

declare(strict_types=1);

namespace LSNepomuceno\LaravelA1PdfSign\Exceptions;

use Exception;
use LSNepomuceno\LaravelA1PdfSign\Enums\CertificationLevel;
use Stringable;

/**
 * A certification that cannot be applied, or a signature a certification forbids.
 *
 * These are enforced rather than documented. A caller who discovers the rule by
 * watching a second signature silently invalidate the first has been told too
 * late, and the file is already wrong.
 *
 * See docs/decisions/0012-certification-signatures.md.
 */
class CertificationException extends Exception implements Stringable
{
    /**
     * A certification has to be the first signature: it states what may happen
     * to the document from here on, and approval signatures already applied are
     * things that happened.
     */
    public static function documentAlreadySigned(int $signatures): self
    {
        return new self(sprintf(
            'a certification has to be the first signature, and this document already carries %d; certify the document before anyone signs it',
            $signatures,
        ));
    }

    public static function alreadyCertified(CertificationLevel $level): self
    {
        return new self(sprintf(
            'this document is already certified as "%s", and ISO 32000-1 §12.8.2.2 allows one certification per document',
            $level->value,
        ));
    }

    /**
     * The exclusion the ADR exists to make obvious rather than discoverable.
     */
    public static function locked(): self
    {
        return new self(
            'this document is certified as "no-changes", which forbids the further revision a signature would append; certify at "form-filling" instead if the document still has to be signed',
        );
    }

    /**
     * The same exclusion, reached by extending an archive rather than by
     * signing. An archive timestamp is a revision like any other.
     */
    public static function forbidsArchiveTimestamp(): self
    {
        return new self(
            'this document is certified as "no-changes", which forbids the further revision an archive timestamp would append',
        );
    }

    public function __toString(): string
    {
        return __CLASS__ . ": [{$this->getCode()}]: {$this->getMessage()}\n";
    }
}
