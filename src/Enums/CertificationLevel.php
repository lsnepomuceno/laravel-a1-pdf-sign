<?php

declare(strict_types=1);

namespace LSNepomuceno\LaravelA1PdfSign\Enums;

/**
 * What a certification signature permits afterwards, ISO 32000-1 §12.8.2.2.
 *
 * A certification signature is the author's, not a signer's: it says what may
 * happen to the document from here on, through the /DocMDP transform. Every
 * other signature this package writes is an approval signature, which asserts
 * what the bytes were and restricts nothing.
 *
 * The names are the permission, not the number. `/P 1` says nothing at all is
 * allowed, which is a statement about the document rather than a level of
 * anything, and a configuration file that reads `no-changes` needs no table
 * beside it.
 *
 * See docs/decisions/0012-certification-signatures.md.
 */
enum CertificationLevel: string
{
    /**
     * Nothing is permitted. Any later change invalidates the certification,
     * including the revision another signature would need.
     */
    case NoChanges = 'no-changes';

    /** Filling form fields and signing. */
    case FormFilling = 'form-filling';

    /** Form filling and signing, plus annotations. */
    case Annotations = 'annotations';

    /**
     * The /P value the transform parameters carry.
     */
    public function permission(): int
    {
        return match ($this) {
            self::NoChanges => 1,
            self::FormFilling => 2,
            self::Annotations => 3,
        };
    }

    /**
     * Whether a document certified at this level can be signed again.
     *
     * This is the standard's intent rather than a limitation of the package: a
     * further signature is a further revision, and at /P 1 a further revision
     * is exactly what was forbidden. The package refuses rather than letting a
     * caller find out when the second signature silently invalidates the first.
     */
    public function allowsFurtherSignatures(): bool
    {
        return $this !== self::NoChanges;
    }

    public static function resolve(self|string $value): self
    {
        return $value instanceof self ? $value : (self::tryFrom($value) ?? self::NoChanges);
    }

    /**
     * The level a /DocMDP transform declares, or null when /P is not one of
     * the three the standard defines.
     */
    public static function fromPermission(int $permission): ?self
    {
        return match ($permission) {
            1 => self::NoChanges,
            2 => self::FormFilling,
            3 => self::Annotations,
            default => null,
        };
    }
}
