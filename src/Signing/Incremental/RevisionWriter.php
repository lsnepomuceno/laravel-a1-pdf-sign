<?php

namespace LSNepomuceno\LaravelA1PdfSign\Signing\Incremental;

use LSNepomuceno\LaravelA1PdfSign\Data\SealImage;
use LSNepomuceno\LaravelA1PdfSign\Data\SealPlacement;
use LSNepomuceno\LaravelA1PdfSign\Data\SignatureInfo;
use LSNepomuceno\LaravelA1PdfSign\Enums\SignatureProfile;
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
        private readonly XrefStreamWriter $streams = new XrefStreamWriter(),
        private readonly XrefSubsections $subsections = new XrefSubsections(),
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
        SignatureProfile $profile = SignatureProfile::PadesBB,
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
        $body .= $this->signatureObject($signatureNumber, $info, $contentsHexLength, $profile);

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

        $body .= $this->crossReference(
            $base + strlen($body),
            $offsets,
            max(array_keys($offsets)) + 1,
            $document,
        );

        return $pdf . $body;
    }

    /**
     * Appends a revision carrying arbitrary object bodies.
     *
     * The signature revision above is one caller; the DSS and document
     * timestamp revisions of PAdES B-LT and B-LTA are the others. Object
     * numbering is the caller's, so it stays authoritative.
     *
     * @param  array<int, string>  $objects  Full "N 0 obj … endobj" fragments, keyed by number.
     *
     * @throws InvalidPdfFileException
     */
    public function appendObjects(string $pdf, DocumentInfo $document, array $objects): string
    {
        if ($objects === []) {
            return $pdf;
        }

        ksort($objects);

        $base = strlen($pdf);
        $body = "\n";
        $offsets = [];

        foreach ($objects as $number => $object) {
            $offsets[$number] = $base + strlen($body);
            $body .= $object;
        }

        $body .= $this->crossReference(
            $base + strlen($body),
            $offsets,
            max(max(array_keys($offsets)) + 1, $document->size),
            $document,
        );

        return $pdf . $body;
    }

    /**
     * The cross-reference section closing a revision, in the form the document
     * already uses.
     *
     * A document whose latest section is a stream cannot be extended with a
     * classic table. Doing it anyway produced a file poppler reported as
     * carrying no signatures at all, which is why this branches rather than
     * emitting one shape for everything
     * (docs/decisions/0009-cross-reference-streams.md).
     *
     * @param  array<int, int>  $offsets  Object number to byte offset.
     * @param  int  $size  One past the highest object number, before the stream
     *                     object this may have to allocate for itself.
     */
    private function crossReference(int $xrefOffset, array $offsets, int $size, DocumentInfo $document): string
    {
        $ending = "startxref\n{$xrefOffset}\n%%EOF\n";

        if (! $document->usesXrefStream) {
            return $this->xrefTable($offsets)
                . $this->trailer($size, $document->root, $document)
                . $ending;
        }

        // The stream is an object, so it consumes a number and indexes itself.
        // /Size is already one past the highest number in the revision, which
        // makes it the first number free for the stream to take.
        $offsets[$size] = $xrefOffset;

        return $this->streams->object(
            $size,
            $offsets,
            $size + 1,
            $document->root,
            $document->infoRef,
            $document->startxref,
        ) . $ending;
    }

    /**
     * The catalog with an extra field registered on its /AcroForm.
     *
     * @throws InvalidPdfFileException
     */
    public function catalogWithField(string $pdf, DocumentInfo $document, int $widgetNumber): string
    {
        $catalog = $this->withAcroForm($this->reader->rawObject($pdf, $document, $document->root), $widgetNumber);

        return "{$document->root} 0 obj\n{$catalog}\nendobj\n";
    }

    /**
     * The page with an extra annotation appended to its /Annots.
     *
     * @throws InvalidPdfFileException
     */
    public function pageWithAnnotation(
        string $pdf,
        DocumentInfo $document,
        int $pageNumber,
        int $widgetNumber,
    ): string {
        $page = $this->withAnnotation($this->reader->rawObject($pdf, $document, $pageNumber), $widgetNumber);

        return "{$pageNumber} 0 obj\n{$page}\nendobj\n";
    }

    /**
     * The catalog with a /DSS entry pointing at the emitted store.
     *
     * @throws InvalidPdfFileException
     */
    public function catalogWithDss(string $pdf, DocumentInfo $document, int $dssNumber): string
    {
        $catalog = $this->reader->rawObject($pdf, $document, $document->root);

        $catalog = preg_match('/\/DSS\s+\d+\s+\d+\s+R/', $catalog) === 1
            ? (string) preg_replace('/\/DSS\s+\d+\s+\d+\s+R/', "/DSS {$dssNumber} 0 R", $catalog)
            : $this->injectBeforeClose($catalog, "/DSS {$dssNumber} 0 R");

        return "{$document->root} 0 obj\n{$catalog}\nendobj\n";
    }

    private function signatureObject(
        int $number,
        SignatureInfo $info,
        int $contentsHexLength,
        SignatureProfile $profile,
    ): string {
        $metadata = '';

        foreach ($info->toDictionary() as $key => $value) {
            $metadata .= "/{$key} (" . $this->escape($value) . ') ';
        }

        return "{$number} 0 obj\n"
            // The /SubFilter must match what the CMS actually is: a PAdES
            // baseline signature is ETSI.CAdES.detached, not adbe.pkcs7.
            . '<</Type/Sig/Filter/Adobe.PPKLite/SubFilter/' . $profile->subFilter() . ' '
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
        if (preg_match('/\/AcroForm\s*<<(.*?)>>/s', $catalog, $matches) !== 1) {
            return $this->injectBeforeClose($catalog, "/AcroForm <</Fields [{$widgetNumber} 0 R]/SigFlags 3>>");
        }

        $acroForm = $matches[1];

        if (preg_match('/\/Fields\s*\[(.*?)\]/s', $acroForm, $fields) === 1) {
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
        if (preg_match('/\/Annots\s*\[(.*?)\]/s', $page, $matches) === 1) {
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
        $out = "xref\n";

        foreach ($this->subsections->of($offsets) as $group) {
            $out .= array_key_first($group) . ' ' . count($group) . "\n";

            foreach ($group as $offset) {
                // Entries are exactly 20 bytes, per ISO 32000-1 §7.5.4.
                $out .= sprintf("%010d %05d n \n", $offset, 0);
            }
        }

        return $out;
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
