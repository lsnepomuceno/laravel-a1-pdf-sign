<?php

declare(strict_types=1);

namespace LSNepomuceno\LaravelA1PdfSign\Signing\Incremental;

use LSNepomuceno\LaravelA1PdfSign\Data\FieldLock;
use LSNepomuceno\LaravelA1PdfSign\Enums\FieldLockAction;
use LSNepomuceno\LaravelA1PdfSign\Exceptions\InvalidPdfFileException;

/**
 * The locks a document's already-signed fields impose.
 *
 * A `/Lock` on an **unsigned** field is a statement about what will happen when
 * it is signed, not about now, so it is ignored here: a template shipping a
 * field that locks everything must not make the template unsignable.
 *
 * See docs/decisions/0021-locking-fields-and-honouring-locks.md.
 *
 * @internal
 */
final readonly class FieldLockReader
{
    public function __construct(
        private DocumentReader $reader,
        private SignatureFieldReader $fields = new SignatureFieldReader(new DocumentReader()),
    ) {}

    /**
     * Every lock in force, keyed by the field whose signature imposed it.
     *
     * @return array<string, FieldLock>
     *
     * @throws InvalidPdfFileException
     */
    public function inForce(string $pdf, ?DocumentInfo $document = null): array
    {
        $document ??= $this->reader->read($pdf);

        $locks = [];

        foreach ($this->fields->read($pdf, $document) as $field) {
            if (! $field->isSigned) {
                continue;
            }

            $lock = $this->lockOf($pdf, $document, $field->objectNumber);

            if ($lock !== null) {
                $locks[$field->name] = $lock;
            }
        }

        return $locks;
    }

    /**
     * The first signed field whose lock covers $name, or null.
     *
     * @throws InvalidPdfFileException
     */
    public function lockOn(string $pdf, string $name, ?DocumentInfo $document = null): ?string
    {
        foreach ($this->inForce($pdf, $document) as $by => $lock) {
            // A field cannot be locked by its own signature: the lock takes
            // effect on everything the signature covers from that point on, and
            // the field itself is already filled.
            if ($by !== $name && $lock->covers($name)) {
                return $by;
            }
        }

        return null;
    }

    /**
     * @throws InvalidPdfFileException
     */
    private function lockOf(string $pdf, DocumentInfo $document, int $number): ?FieldLock
    {
        $widget = $this->reader->rawObject($pdf, $document, $number);

        if (preg_match('/\/Lock\s*<<(.*?)>>/s', $widget, $found) !== 1) {
            return null;
        }

        if (preg_match('#/Action\s*/(\w+)#', $found[1], $action) !== 1) {
            return null;
        }

        $resolved = FieldLockAction::fromPdfName($action[1]);

        if ($resolved === null) {
            return null;
        }

        return new FieldLock($resolved, $this->names($found[1]));
    }

    /**
     * @return list<string>
     */
    private function names(string $lock): array
    {
        if (preg_match('/\/Fields\s*\[(.*?)\]/s', $lock, $found) !== 1) {
            return [];
        }

        preg_match_all('/\((.*?)(?<!\\\\)\)/s', $found[1], $names);

        return array_map(
            static fn(string $name): string => str_replace(['\\(', '\\)', '\\\\'], ['(', ')', '\\'], $name),
            $names[1],
        );
    }
}
