<?php

namespace LSNepomuceno\LaravelA1PdfSign\Signing\Incremental;

use LSNepomuceno\LaravelA1PdfSign\Data\SealImage;
use LSNepomuceno\LaravelA1PdfSign\Data\SealPlacement;
use LSNepomuceno\LaravelA1PdfSign\Data\SignatureInfo;
use LSNepomuceno\LaravelA1PdfSign\Exceptions\InvalidPdfFileException;

/**
 * Builds the revision appended to a document when it is signed.
 *
 * Clean-room implementation from ISO 32000-1 §7.5.6 (Incremental Updates) and
 * §12.8 (Digital Signatures). The original bytes are never touched; the
 * revision carries only the objects that change.
 *
 * @internal
 */
final class RevisionWriter
{
    public function __construct(
        private readonly DocumentReader $reader,
        private readonly SealAppearance $appearance = new SealAppearance(),
    ) {}

    /**
     * Appends the signature revision, leaving the /Contents placeholder empty.
     *
     * @throws InvalidPdfFileException
     */
    public function append(
        string $pdf,
        DocumentInfo $document,
        SignatureInfo $info,
        int $contentsHexLength,
        string $fieldName,
        ?SealImage $seal = null,
        ?SealPlacement $placement = null,
    ): string {
        $visible = $seal !== null && $placement !== null;

        $signatureNumber = $document->nextObjectNumber();
        $widgetNumber = $signatureNumber + 1;
        $imageNumber = $signatureNumber + 2;
        $formNumber = $signatureNumber + 3;

        $catalogNumber = $document->root;
        $pageNumber = $this->reader->findFirstPage($pdf, $document);

        $catalog = $this->withAcroForm($this->reader->rawObject($pdf, $document, $catalogNumber), $widgetNumber);
        $page = $this->withAnnotation($this->reader->rawObject($pdf, $document, $pageNumber), $widgetNumber);

        $base = strlen($pdf);
        $body = "\n";
        $offsets = [];

        $offsets[$signatureNumber] = $base + strlen($body);
        $body .= $this->signatureObject($signatureNumber, $info, $contentsHexLength);

        $offsets[$widgetNumber] = $base + strlen($body);
        $body .= $this->widgetObject(
            $widgetNumber,
            $signatureNumber,
            $pageNumber,
            $fieldName,
            $visible ? $formNumber : null,
            $visible ? $this->appearance->rectangle($placement, $seal) : null,
        );

        if ($visible) {
            $offsets[$imageNumber] = $base + strlen($body);
            $body .= $this->appearance->imageObject($imageNumber, $seal);

            $offsets[$formNumber] = $base + strlen($body);
            $body .= $this->appearance->formObject($formNumber, $imageNumber, $placement, $seal);
        }

        $offsets[$catalogNumber] = $base + strlen($body);
        $body .= "{$catalogNumber} 0 obj\n{$catalog}\nendobj\n";

        $offsets[$pageNumber] = $base + strlen($body);
        $body .= "{$pageNumber} 0 obj\n{$page}\nendobj\n";

        $xrefOffset = $base + strlen($body);

        $body .= $this->xrefTable($offsets);
        $body .= $this->trailer(max(array_keys($offsets)) + 1, $catalogNumber, $document);
        $body .= "startxref\n{$xrefOffset}\n%%EOF\n";

        return $pdf . $body;
    }

    private function signatureObject(int $number, SignatureInfo $info, int $contentsHexLength): string
    {
        $metadata = '';

        foreach ($info->toDictionary() as $key => $value) {
            $metadata .= "/{$key} (" . $this->escape($value) . ') ';
        }

        return "{$number} 0 obj\n"
            . '<</Type/Sig/Filter/Adobe.PPKLite/SubFilter/adbe.pkcs7.detached '
            . ByteRangeCalculator::placeholder()
            . '/Contents <' . str_repeat('0', $contentsHexLength) . '> '
            . $metadata
            . '/M (' . $this->timestamp() . ')'
            . ">>\nendobj\n";
    }

    /**
     * @param  array{0: float, 1: float, 2: float, 3: float}|null  $rectangle
     */
    private function widgetObject(
        int $number,
        int $signatureNumber,
        int $pageNumber,
        string $fieldName,
        ?int $formNumber = null,
        ?array $rectangle = null,
    ): string {
        // A zero rectangle keeps the signature invisible, which is the default
        // when no seal was supplied.
        $rect = $rectangle === null
            ? '/Rect[0 0 0 0]'
            : sprintf('/Rect[%s %s %s %s]', ...$rectangle);

        $appearance = $formNumber === null ? '' : "/AP<</N {$formNumber} 0 R>>";

        return "{$number} 0 obj\n"
            . '<</Type/Annot/Subtype/Widget/FT/Sig'
            . $rect
            . $appearance
            . '/T (' . $this->escape($fieldName) . ')'
            . "/V {$signatureNumber} 0 R"
            . "/P {$pageNumber} 0 R"
            . '/F 132'
            . '/Ff 0'
            . ">>\nendobj\n";
    }

    /**
     * Adds the field to /AcroForm, extending an existing one rather than
     * replacing it, so signatures already present keep their fields.
     */
    private function withAcroForm(string $catalog, int $widgetNumber): string
    {
        if (! preg_match('/\/AcroForm\s*<<(.*?)>>/s', $catalog, $matches)) {
            return $this->injectBeforeClose($catalog, "/AcroForm <</Fields [{$widgetNumber} 0 R]/SigFlags 3>>");
        }

        $acroForm = $matches[1];

        if (preg_match('/\/Fields\s*\[(.*?)\]/s', $acroForm, $fields)) {
            $acroForm = (string) preg_replace(
                '/\/Fields\s*\[.*?\]/s',
                '/Fields [' . trim(trim($fields[1]) . " {$widgetNumber} 0 R") . ']',
                $acroForm,
                1,
            );
        } else {
            $acroForm .= "/Fields [{$widgetNumber} 0 R]";
        }

        if (! str_contains($acroForm, '/SigFlags')) {
            $acroForm .= '/SigFlags 3';
        }

        return str_replace($matches[0], '/AcroForm <<' . $acroForm . '>>', $catalog);
    }

    private function withAnnotation(string $page, int $widgetNumber): string
    {
        if (preg_match('/\/Annots\s*\[(.*?)\]/s', $page, $matches)) {
            return str_replace(
                $matches[0],
                '/Annots [' . trim(trim($matches[1]) . " {$widgetNumber} 0 R") . ']',
                $page,
            );
        }

        return $this->injectBeforeClose($page, "/Annots [{$widgetNumber} 0 R]");
    }

    /**
     * @throws InvalidPdfFileException
     */
    private function injectBeforeClose(string $dictionary, string $entry): string
    {
        $position = strrpos($dictionary, '>>');

        if ($position === false) {
            throw new InvalidPdfFileException('malformed dictionary: no closing >>');
        }

        return substr($dictionary, 0, $position) . $entry . substr($dictionary, $position);
    }

    /**
     * @param  array<int, int>  $offsets
     */
    private function xrefTable(array $offsets): string
    {
        ksort($offsets);

        $out = "xref\n";

        foreach ($this->consecutiveGroups($offsets) as $group) {
            $out .= array_key_first($group) . ' ' . count($group) . "\n";

            foreach ($group as $offset) {
                // Entries are exactly 20 bytes, per ISO 32000-1 §7.5.4.
                $out .= sprintf("%010d %05d n \n", $offset, 0);
            }
        }

        return $out;
    }

    /**
     * @param  array<int, int>  $offsets
     * @return array<int, array<int, int>>
     */
    private function consecutiveGroups(array $offsets): array
    {
        $groups = [];
        $current = [];
        $previous = null;

        foreach ($offsets as $number => $offset) {
            if ($previous !== null && $number !== $previous + 1) {
                $groups[] = $current;
                $current = [];
            }

            $current[$number] = $offset;
            $previous = $number;
        }

        if ($current !== []) {
            $groups[] = $current;
        }

        return $groups;
    }

    private function trailer(int $size, int $root, DocumentInfo $document): string
    {
        $info = $document->infoRef !== null ? "/Info {$document->infoRef}" : '';

        return "trailer\n<</Size {$size}/Root {$root} 0 R{$info}/Prev {$document->startxref}>>\n";
    }

    private function timestamp(): string
    {
        return 'D:' . date('YmdHis') . "+00'00'";
    }

    private function escape(string $value): string
    {
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $value);
    }
}
