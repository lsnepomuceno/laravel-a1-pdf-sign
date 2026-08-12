<?php

declare(strict_types=1);

namespace LSNepomuceno\LaravelA1PdfSign\Signing\Incremental;

use LSNepomuceno\LaravelA1PdfSign\Data\SignatureField;
use LSNepomuceno\LaravelA1PdfSign\Exceptions\InvalidPdfFileException;
use LSNepomuceno\LaravelA1PdfSign\Support\PdfDictionary;

/**
 * Lists the signature fields a document already carries.
 *
 * The catalog's /AcroForm /Fields is the authoritative list, ISO 32000-1
 * §12.7.2, so this walks it rather than scanning the file for /FT /Sig. A
 * widget that is not registered there is not a form field, and filling it would
 * leave the form saying the document has an empty field.
 *
 * See docs/decisions/0013-signing-into-an-existing-field.md.
 *
 * @internal
 */
final readonly class SignatureFieldReader
{
    public function __construct(
        private DocumentReader $reader,
        private PdfDictionary $dictionaries = new PdfDictionary(),
    ) {}

    /**
     * @return list<SignatureField> In the order /Fields declares them.
     *
     * @throws InvalidPdfFileException
     */
    public function read(string $pdf, ?DocumentInfo $document = null): array
    {
        $document ??= $this->reader->read($pdf);

        $acroForm = $this->acroForm($pdf, $document);

        if ($acroForm === null || preg_match('/\/Fields\s*\[(.*?)\]/s', $acroForm, $fields) !== 1) {
            return [];
        }

        preg_match_all('/(\d+)\s+\d+\s+R/', $fields[1], $references);

        $found = [];

        foreach ($references[1] as $reference) {
            $field = $this->field($pdf, $document, (int) $reference);

            if ($field !== null) {
                $found[] = $field;
            }
        }

        return $found;
    }

    /**
     * @throws InvalidPdfFileException
     */
    public function named(string $pdf, string $name, ?DocumentInfo $document = null): ?SignatureField
    {
        foreach ($this->read($pdf, $document) as $field) {
            if ($field->name === $name) {
                return $field;
            }
        }

        return null;
    }

    /**
     * The interactive form dictionary, however the catalog holds it.
     *
     * Two forms are legal and both occur. Acrobat writes /AcroForm inline and
     * nests /DR inside it, so a non-greedy match to the first ">>" stops before
     * /Fields is reached; depth counting is what makes the nested case work.
     * Other producers write /AcroForm as an indirect reference, which has no
     * dictionary at the catalog at all and has to be followed.
     *
     * @throws InvalidPdfFileException
     */
    private function acroForm(string $pdf, DocumentInfo $document): ?string
    {
        $catalog = $this->reader->rawObject($pdf, $document, $document->root);

        $position = strpos($catalog, '/AcroForm');

        if ($position === false) {
            return null;
        }

        if (preg_match('/\/AcroForm\s+(\d+)\s+\d+\s+R/', $catalog, $reference) === 1) {
            return isset($document->xref[(int) $reference[1]])
                ? $this->reader->rawObject($pdf, $document, (int) $reference[1])
                : null;
        }

        $open = strpos($catalog, '<<', $position);

        return $open === false ? null : $this->dictionaries->at($catalog, $open);
    }

    /**
     * @throws InvalidPdfFileException
     */
    private function field(string $pdf, DocumentInfo $document, int $number): ?SignatureField
    {
        if (! isset($document->xref[$number])) {
            return null;
        }

        $object = $this->reader->rawObject($pdf, $document, $number);

        // Non-signature form fields share the list: a template usually carries
        // text and checkbox fields beside its signature fields.
        if (preg_match('/\/FT\s*\/Sig\b/', $object) !== 1) {
            return null;
        }

        if (preg_match('/\/T\s*\((.*?)(?<!\\\\)\)/s', $object, $title) !== 1) {
            return null;
        }

        return new SignatureField(
            name: $this->unescape($title[1]),
            // /V holds the signature dictionary. Its presence is what "signed"
            // means; the widget itself looks the same either way.
            isSigned: preg_match('/\/V\s+\d+\s+\d+\s+R/', $object) === 1,
            objectNumber: $number,
            pageNumber: preg_match('/\/P\s+(\d+)\s+\d+\s+R/', $object, $page) === 1 ? (int) $page[1] : 0,
            rectangle: $this->rectangle($object),
        );
    }

    /**
     * @return array{0: float, 1: float, 2: float, 3: float}
     */
    private function rectangle(string $object): array
    {
        if (preg_match('/\/Rect\s*\[\s*([\d.+-]+)\s+([\d.+-]+)\s+([\d.+-]+)\s+([\d.+-]+)/', $object, $rect) !== 1) {
            return [0.0, 0.0, 0.0, 0.0];
        }

        return [(float) $rect[1], (float) $rect[2], (float) $rect[3], (float) $rect[4]];
    }

    private function unescape(string $value): string
    {
        return str_replace(['\\(', '\\)', '\\\\'], ['(', ')', '\\'], $value);
    }
}
