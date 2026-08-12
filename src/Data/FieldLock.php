<?php

declare(strict_types=1);

namespace LSNepomuceno\LaravelA1PdfSign\Data;

use LSNepomuceno\LaravelA1PdfSign\Enums\FieldLockAction;

/**
 * Which form fields stop being fillable once this signature is applied.
 *
 * ISO 32000-1 §12.7.4.5: the lock lives on the signature field, and the
 * /FieldMDP transform in the signature itself is what makes a reader enforce
 * it. The package writes both, because either one alone is a document readers
 * disagree about, which is the same rule certification follows
 * ([0012](../../docs/decisions/0012-certification-signatures.md)).
 *
 * See docs/decisions/0021-locking-fields-and-honouring-locks.md.
 */
final readonly class FieldLock extends BaseData
{
    /**
     * @param  list<string>  $fields  Field names, empty for the /All action.
     */
    public function __construct(
        public FieldLockAction $action = FieldLockAction::All,
        public array $fields = [],
    ) {}

    /**
     * Locks every field in the document, whenever it was added.
     */
    public static function all(): self
    {
        return new self(FieldLockAction::All);
    }

    /**
     * @param  list<string>  $fields
     */
    public static function only(array $fields): self
    {
        return new self(FieldLockAction::Include, array_values($fields));
    }

    /**
     * @param  list<string>  $fields
     */
    public static function except(array $fields): self
    {
        return new self(FieldLockAction::Exclude, array_values($fields));
    }

    /**
     * Whether this lock covers a field of that name.
     */
    public function covers(string $field): bool
    {
        return match ($this->action) {
            FieldLockAction::All => true,
            FieldLockAction::Include => in_array($field, $this->fields, true),
            FieldLockAction::Exclude => ! in_array($field, $this->fields, true),
        };
    }

    /**
     * The lock dictionary as it goes into the widget.
     */
    public function toDictionary(): string
    {
        $fields = $this->action->needsFields()
            ? '/Fields[' . implode('', array_map(
                static fn(string $field): string => '(' . str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $field) . ')',
                $this->fields,
            )) . ']'
            : '';

        return '<</Type/SigFieldLock/Action/' . $this->action->pdfName() . $fields . '>>';
    }
}
