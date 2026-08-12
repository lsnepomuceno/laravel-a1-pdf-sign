<?php

namespace LSNepomuceno\LaravelA1PdfSign\Enums;

/**
 * What a signature field lock covers, ISO 32000-1 §12.7.4.5, table 233.
 *
 * The lock says which form fields stop being fillable once this field is
 * signed. It is a narrower claim than a certification: a certification
 * ([0012](../../docs/decisions/0012-certification-signatures.md)) governs the
 * whole document, a lock governs named fields.
 */
enum FieldLockAction: string
{
    /** Every field in the document, however many arrive later. */
    case All = 'all';

    /** Only the fields named. */
    case Include = 'include';

    /** Every field except the ones named. */
    case Exclude = 'exclude';

    /**
     * The /Action name the PDF dictionary carries.
     *
     * Capitalised there and lowercase here, so configuration can express a case
     * as plain text (docs/decisions/0018-prefer-the-platforms-own-constructs.md).
     */
    public function pdfName(): string
    {
        return ucfirst($this->value);
    }

    public static function fromPdfName(string $name): ?self
    {
        return self::tryFrom(strtolower($name));
    }

    /**
     * Whether this action needs a list of field names to mean anything.
     *
     * /Include with no fields locks nothing and /Exclude with no fields locks
     * everything, so both are almost certainly a caller mistake rather than an
     * intention.
     */
    public function needsFields(): bool
    {
        return $this !== self::All;
    }
}
