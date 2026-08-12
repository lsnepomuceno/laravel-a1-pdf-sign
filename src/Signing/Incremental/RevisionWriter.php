<?php

namespace LSNepomuceno\LaravelA1PdfSign\Signing\Incremental;

use LSNepomuceno\LaravelA1PdfSign\Data\FieldLock;
use LSNepomuceno\LaravelA1PdfSign\Data\SealImage;
use LSNepomuceno\LaravelA1PdfSign\Data\SealPlacement;
use LSNepomuceno\LaravelA1PdfSign\Data\SignatureField;
use LSNepomuceno\LaravelA1PdfSign\Data\SignatureInfo;
use LSNepomuceno\LaravelA1PdfSign\Enums\CertificationLevel;
use LSNepomuceno\LaravelA1PdfSign\Enums\SignatureProfile;
use LSNepomuceno\LaravelA1PdfSign\Exceptions\InvalidPdfFileException;
use LSNepomuceno\LaravelA1PdfSign\Exceptions\SealPlacementException;
use LSNepomuceno\LaravelA1PdfSign\Signing\Encryption\ObjectCipher;
use LSNepomuceno\LaravelA1PdfSign\Support\SrgbProfile;

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
        private readonly DocumentReader   $reader,
        private readonly SealAppearance   $appearance = new SealAppearance(),
        private readonly XrefStreamWriter $streams = new XrefStreamWriter(),
        private readonly XrefSubsections  $subsections = new XrefSubsections(),
    ) {}

    /**
     * Appends the signature revision, leaving the /Contents placeholder empty.
     *
     * @throws InvalidPdfFileException|SealPlacementException
     */
    public function append(
        string           $pdf,
        DocumentInfo     $document,
        SignatureInfo    $info,
        int              $contentsHexLength,
        string           $fieldName,
        ?SealImage       $seal = null,
        ?SealPlacement   $placement = null,
        SignatureProfile $profile = SignatureProfile::PadesBB,
        ?SignatureField  $target = null,
        ?CertificationLevel $certification = null,
        ?FieldLock       $lock = null,
    ): string {
        $visible = $seal !== null && $placement !== null;

        // Inactive for an ordinary document, so every emitter below writes the
        // same code whether or not the file is encrypted
        // (docs/decisions/0030-signing-a-document-that-is-encrypted.md).
        $cipher = ObjectCipher::for($document);

        // Where the seal was asked for, in page-tree order. Empty whenever the
        // signature is invisible, since a zero rectangle appears on no page at
        // all and the widget then only has to sit somewhere legal.
        $sealed = $visible ? $this->sealedPages($pdf, $document, $placement) : [];

        $number = $document->nextObjectNumber();
        $signatureNumber = $number++;
        // Filling a field keeps the widget's own number: the revision replaces
        // that object rather than adding a second one beside it, which is the
        // whole point (docs/decisions/0013-signing-into-an-existing-field.md).
        $widgetNumber = $target === null ? $number++ : $target->objectNumber;
        $imageNumber = $number++;
        // Allocated whether or not the seal is transparent, so the numbers
        // below do not depend on the artwork
        // (docs/decisions/0023-a-seal-that-can-be-transparent.md).
        $maskNumber = $number++;
        // Allocated on the same terms as the mask: the numbers below must not
        // depend on the artwork, and they must not depend on the colour space
        // either.
        $profileNumber = $number++;
        $formNumber = $number++;

        // Both widget builders point /AP here. An invisible signature gets an
        // empty form rather than no appearance at all: ISO 19005-1 §6.9 wants
        // every form field to have an appearance dictionary, and veraPDF fails
        // a signed PDF/A-1 document without one
        // (docs/decisions/0025-what-signing-does-to-pdf-a.md).
        $appearanceNumber = $formNumber;

        $catalogNumber = $document->root;
        // A field states its own page through /P, and that wins: intoField()
        // refuses a placement precisely so there is nothing to resolve here.
        // Falling back to the first page covers the field that declares no page,
        // which is legal and leaves the widget wherever the form puts it.
        $pageNumber = $target !== null && $target->pageNumber > 0
            ? $target->pageNumber
            : ($sealed[0] ?? $this->reader->findFirstPage($pdf, $document));

        // The signature is one widget on one page, so every further page the
        // placement names gets a stamp annotation drawing the same appearance
        // (docs/decisions/0017-the-seal-goes-where-it-was-asked-for.md).
        $stampNumbers = [];

        foreach (array_slice($sealed, 1) as $page) {
            $stampNumbers[$page] = $number++;
        }

        $base = strlen($pdf);
        $body = "\n";
        $offsets = [];

        $offsets[$signatureNumber] = $base + strlen($body);
        $body .= $this->signatureObject($signatureNumber, $info, $contentsHexLength, $profile, $certification, $lock, $catalogNumber, $cipher);

        $offsets[$widgetNumber] = $base + strlen($body);
        $body .= $target === null
            ? $this->widgetObject(
                $widgetNumber,
                $signatureNumber,
                $pageNumber,
                $fieldName,
                $appearanceNumber,
                $visible ? $this->appearance->rectangle($placement, $seal) : null,
                $lock,
                $cipher,
            )
            : $this->filledWidget($pdf, $document, $target, $signatureNumber, $appearanceNumber, $lock);

        if ($visible) {
            $offsets[$imageNumber] = $base + strlen($body);
            $body .= $this->appearance->imageObject($imageNumber, $seal, $maskNumber, $profileNumber, $cipher);

            $offsets[$profileNumber] = $base + strlen($body);
            $body .= $this->appearance->profileObject($profileNumber, new SrgbProfile()->bytes(), $cipher);

            if ($seal->isTransparent()) {
                $offsets[$maskNumber] = $base + strlen($body);
                $body .= $this->appearance->maskObject($maskNumber, $seal, $cipher);
            }

            $offsets[$formNumber] = $base + strlen($body);
            $body .= $this->appearance->formObject($formNumber, $imageNumber, $placement, $seal, $cipher);

            $rectangle = $this->appearance->rectangle($placement, $seal);

            foreach ($stampNumbers as $page => $stampNumber) {
                $offsets[$stampNumber] = $base + strlen($body);
                $body .= $this->stampObject($stampNumber, $formNumber, $page, $rectangle);
            }
        } else {
            $offsets[$formNumber] = $base + strlen($body);
            $body .= $this->appearance->emptyForm($formNumber, $cipher);
        }

        // A field the document already carries is already registered on the
        // form and on its page, so those objects are rewritten only when they
        // do not yet say so. Emitting them unchanged would grow every revision
        // with two objects that decide nothing.
        $catalog = $this->reader->rawObject($pdf, $document, $catalogNumber);

        // A certification always rewrites the catalog: /Perms names the
        // signature that certified the document, and the entry and the
        // transform are written together or not at all
        // (docs/decisions/0012-certification-signatures.md).
        if ($target === null || $certification !== null || ! str_contains($catalog, '/SigFlags')) {
            $catalog = $this->withAcroForm($catalog, $widgetNumber, $target !== null);

            if ($certification !== null) {
                $catalog = $this->withDocMdpPermission($catalog, $signatureNumber);
            }

            $offsets[$catalogNumber] = $base + strlen($body);
            $body .= "{$catalogNumber} 0 obj\n{$catalog}\nendobj\n";
        }

        $page = $this->reader->rawObject($pdf, $document, $pageNumber);

        // A transparent seal makes the page carry transparency, and ISO 19005-2
        // §6.2.10 then wants a group naming the blending colour space, unless
        // the file has an OutputIntent to answer for it.
        $group = $visible && $seal->isTransparent() ? $profileNumber : null;

        if ($target === null || preg_match('/\/Annots\s*\[[^\]]*\b' . $widgetNumber . '\s+\d+\s+R/', $page) !== 1) {
            $offsets[$pageNumber] = $base + strlen($body);
            $body .= "{$pageNumber} 0 obj\n"
                . $this->withTransparencyGroup($this->withAnnotation($page, $widgetNumber), $group)
                . "\nendobj\n";
        }

        foreach ($stampNumbers as $stampPage => $stampNumber) {
            $offsets[$stampPage] = $base + strlen($body);
            $body .= "{$stampPage} 0 obj\n"
                . $this->withTransparencyGroup(
                    $this->withAnnotation($this->reader->rawObject($pdf, $document, $stampPage), $stampNumber),
                    $group,
                )
                . "\nendobj\n";
        }

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
     * @param array<int, string> $objects Full "N 0 obj … endobj" fragments, keyed by number.
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
     * @param array<int, int> $offsets Object number to byte offset.
     * @param int $size One past the highest object number, before the stream
     *                     object this may have to allocate for itself.
     */
    private function crossReference(int $xrefOffset, array $offsets, int $size, DocumentInfo $document): string
    {
        $ending = "startxref\n{$xrefOffset}\n%%EOF\n";

        if (!$document->usesXrefStream) {
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
            $document->id,
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
        string       $pdf,
        DocumentInfo $document,
        int          $pageNumber,
        int          $widgetNumber,
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
        int              $number,
        SignatureInfo    $info,
        int              $contentsHexLength,
        SignatureProfile $profile,
        ?CertificationLevel $certification = null,
        ?FieldLock       $lock = null,
        int              $catalogNumber = 0,
        ?ObjectCipher    $cipher = null,
    ): string {
        $cipher ??= new ObjectCipher();
        $metadata = '';

        foreach ($info->toDictionary() as $key => $value) {
            $metadata .= "/{$key} " . $cipher->text($value, $number) . ' ';
        }

        return "{$number} 0 obj\n"
            // The /SubFilter must match what the CMS actually is: a PAdES
            // baseline signature is ETSI.CAdES.detached, not adbe.pkcs7.
            . '<</Type/Sig/Filter/Adobe.PPKLite/SubFilter/' . $profile->subFilter() . ' '
            . ByteRangeCalculator::placeholder()
            . '/Contents <' . str_repeat('0', $contentsHexLength) . '> '
            . $metadata
            . $this->references($certification, $lock, $catalogNumber)
            // /Contents is deliberately not encrypted, and it is the one entry
            // that must not be: ISO 32000-1 §7.6.2 excludes it, because it is
            // the signature over the bytes rather than content of the document.
            . '/M ' . $cipher->text($this->timestamp(), $number)
            . ">>\nendobj\n";
    }

    /**
     * The signature's /Reference array, ISO 32000-1 §12.8.2.
     *
     * One array holding both transforms, because a signature may certify the
     * document *and* lock fields, and writing two /Reference entries would
     * leave a reader to pick one.
     *
     * /V is the version of each transform's own parameter dictionary, fixed at
     * 1.2 for both. It is unrelated to the PDF version and to the profile.
     */
    private function references(?CertificationLevel $certification, ?FieldLock $lock, int $catalogNumber): string
    {
        $entries = '';

        if ($certification !== null) {
            $entries .= '<</Type/SigRef/TransformMethod/DocMDP'
                . '/TransformParams<</Type/TransformParams/P ' . $certification->permission() . '/V/1.2>>'
                . '>>';
        }

        if ($lock !== null) {
            // FieldMDP is what a reader enforces; the widget's /Lock is what it
            // shows. Writing only the /Lock produces a document that says the
            // fields are locked and lets them be filled anyway
            // (docs/decisions/0021-locking-fields-and-honouring-locks.md).
            $entries .= '<</Type/SigRef/TransformMethod/FieldMDP'
                . '/TransformParams<</Type/TransformParams'
                . '/Action/' . $lock->action->pdfName()
                . $this->lockFields($lock)
                . '/V/1.2>>'
                // §12.8.2.4: /Data names the object the transform applies to,
                // which for FieldMDP is the document catalog.
                . "/Data {$catalogNumber} 0 R"
                . '>>';
        }

        return $entries === '' ? '' : "/Reference[{$entries}]";
    }

    private function lockFields(FieldLock $lock): string
    {
        if (! $lock->action->needsFields()) {
            return '';
        }

        $names = array_map(fn(string $field): string => '(' . $this->escape($field) . ')', $lock->fields);

        return '/Fields[' . implode('', $names) . ']';
    }

    /**
     * The catalog's /Perms, naming the signature that certified the document.
     *
     * An existing entry is replaced rather than joined. A second certification
     * is refused before reaching here, but that does not make this redundant: a
     * /Perms pointing at anything other than the transform actually present is
     * a document readers disagree about, and leaving a stale one behind would
     * produce exactly that.
     */
    private function withDocMdpPermission(string $catalog, int $signatureNumber): string
    {
        if (preg_match('/\/Perms\s*<<.*?>>/s', $catalog) === 1) {
            return (string) preg_replace(
                '/\/Perms\s*<<.*?>>/s',
                "/Perms<</DocMDP {$signatureNumber} 0 R>>",
                $catalog,
                1,
            );
        }

        return $this->injectBeforeClose($catalog, "/Perms<</DocMDP {$signatureNumber} 0 R>>");
    }

    /**
     * @param array{0: float, 1: float, 2: float, 3: float}|null $rectangle
     */
    private function widgetObject(
        int    $number,
        int    $signatureNumber,
        int    $pageNumber,
        string $fieldName,
        ?int   $formNumber = null,
        ?array $rectangle = null,
        ?FieldLock $lock = null,
        ?ObjectCipher $cipher = null,
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
            . '/T ' . ($cipher ?? new ObjectCipher())->text($fieldName, $number)
            . "/V {$signatureNumber} 0 R"
            . "/P {$pageNumber} 0 R"
            . '/F 132'
            . '/Ff 0'
            . ($lock === null ? '' : '/Lock ' . $lock->toDictionary())
            . ">>\nendobj\n";
    }

    /**
     * The pages the placement puts the seal on, in page-tree order.
     *
     * The placement answers page by page rather than being interrogated for a
     * number, so SealPlacement::LAST_PAGE and $onEveryPage are decided in the
     * one place that defines them.
     *
     * @return list<int> Object numbers. Never empty: a placement that matches no
     *                   page is a caller mistake and raises rather than resolving
     *                   to something plausible.
     *
     * @throws InvalidPdfFileException|SealPlacementException
     */
    private function sealedPages(string $pdf, DocumentInfo $document, SealPlacement $placement): array
    {
        // A tree that cannot be walked still has a page, and treating it as a
        // document of one keeps the fallback honest: LAST_PAGE and page 1 both
        // land on it, and page 3 says so instead of guessing.
        $pages = $this->reader->pages($pdf, $document);

        if ($pages === []) {
            $pages = [$this->reader->findFirstPage($pdf, $document)];
        }

        $count = count($pages);
        $sealed = [];

        foreach ($pages as $index => $number) {
            if ($placement->appliesTo($index + 1, $count)) {
                $sealed[] = $number;
            }
        }

        if ($sealed === []) {
            throw SealPlacementException::pageOutOfRange($placement->page, $count);
        }

        return $sealed;
    }

    /**
     * A stamp annotation drawing the seal's appearance on a further page.
     *
     * ISO 32000-1 §12.5.6.12. It is not a widget: a widget that is not a form
     * field is invalid, and the signature has exactly one field. The stamp is
     * written in the signature's own revision, so the bytes it adds fall inside
     * /ByteRange and the signature covers it like everything else.
     *
     * @param  array{0: float, 1: float, 2: float, 3: float}  $rectangle
     */
    private function stampObject(int $number, int $formNumber, int $pageNumber, array $rectangle): string
    {
        return "{$number} 0 obj\n"
            . '<</Type/Annot/Subtype/Stamp'
            . sprintf('/Rect[%s %s %s %s]', ...$rectangle)
            . "/AP<</N {$formNumber} 0 R>>"
            . "/P {$pageNumber} 0 R"
            // Print, and locked against a reader offering to move or delete it.
            . '/F 132'
            . ">>\nendobj\n";
    }

    /**
     * The widget of a field the document already carries, with /V pointing at
     * the new signature.
     *
     * Everything else the template put there survives: the rectangle, the page
     * reference, the flags. /V is added rather than replaced because a field
     * that already has one was refused before reaching here.
     *
     * @throws InvalidPdfFileException
     */
    private function filledWidget(
        string         $pdf,
        DocumentInfo   $document,
        SignatureField $target,
        int            $signatureNumber,
        ?int           $formNumber,
        ?FieldLock     $lock = null,
    ): string {
        $widget = $this->injectBeforeClose(
            $this->reader->rawObject($pdf, $document, $target->objectNumber),
            "/V {$signatureNumber} 0 R",
        );

        if ($lock !== null) {
            // A template may already carry a /Lock of its own, and the caller
            // asking for one now is asking for theirs: replaced rather than
            // joined, since two locks on one field settle nothing.
            $widget = preg_match('/\/Lock\s*<<.*?>>/s', $widget) === 1
                ? (string) preg_replace('/\/Lock\s*<<.*?>>/s', '/Lock ' . $lock->toDictionary(), $widget, 1)
                : $this->injectBeforeClose($widget, '/Lock ' . $lock->toDictionary());
        }

        if ($formNumber !== null) {
            // An empty signature field often ships with an appearance of its
            // own, the "sign here" graphic, which the seal replaces.
            $widget = preg_match('/\/AP\s*<<.*?>>/s', $widget) === 1
                ? (string) preg_replace('/\/AP\s*<<.*?>>/s', "/AP<</N {$formNumber} 0 R>>", $widget, 1)
                : $this->injectBeforeClose($widget, "/AP<</N {$formNumber} 0 R>>");
        }

        return "{$target->objectNumber} 0 obj\n{$widget}\nendobj\n";
    }

    /**
     * Adds the field to /AcroForm, extending an existing one rather than
     * replacing it, so signatures already present keep their fields.
     *
     * @param bool $alreadyListed True when filling a field the form already
     *                               declares: /SigFlags still has to be right,
     *                               but listing the field twice would leave the
     *                               form carrying a duplicate.
     * @throws InvalidPdfFileException
     */
    private function withAcroForm(string $catalog, int $widgetNumber, bool $alreadyListed = false): string
    {
        if (preg_match('/\/AcroForm\s*<<(.*?)>>/s', $catalog, $matches) !== 1) {
            return $this->injectBeforeClose($catalog, "/AcroForm <</Fields [{$widgetNumber} 0 R]/SigFlags 3>>");
        }

        $acroForm = $matches[1];

        if ($alreadyListed) {
            return str_replace($matches[0], '/AcroForm <<' . $acroForm . '/SigFlags 3>>', $catalog);
        }

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

        if (!str_contains($acroForm, '/SigFlags')) {
            $acroForm .= '/SigFlags 3';
        }

        return str_replace($matches[0], '/AcroForm <<' . $acroForm . '>>', $catalog);
    }

    private function withAnnotation(string $page, int $widgetNumber): string
    {
        if (preg_match('/\/Annots\s*\[(.*?)\]/s', $page, $matches) === 1) {
            $page = str_replace(
                $matches[0],
                '/Annots [' . trim(trim($matches[1]) . " {$widgetNumber} 0 R") . ']',
                $page,
            );

            return $this->withTabOrder($page);
        }

        return $this->withTabOrder($this->injectBeforeClose($page, "/Annots [{$widgetNumber} 0 R]"));
    }

    /**
     * The page's tab order, which becomes required the moment it carries an
     * annotation.
     *
     * *ISO 14289-1 7.18.3: every page on which there is an annotation shall
     * contain in its page dictionary the key /Tabs, and its value shall be S.*
     *
     * Measured before it was written: an invisible signature cost a PDF/UA
     * document its conformance, on this clause and no other
     * (docs/decisions/0032-what-signing-does-to-pdf-ua.md). The page object is
     * already being rewritten to carry the widget in /Annots, so this is a key
     * in a write that was happening anyway.
     *
     * A page that already declares /Tabs is left alone, whatever it declares.
     * /S is what accessibility asks for, and /R and /C are legitimate choices a
     * producer makes about their own document: overwriting one would be the
     * signer deciding how somebody else's page is navigated. A document that
     * arrives claiming PDF/UA already has /S here.
     */
    private function withTabOrder(string $page): string
    {
        return preg_match('#/Tabs\s*/[A-Za-z]#', $page) === 1
            ? $page
            : $this->injectBeforeClose($page, '/Tabs/S');
    }

    /**
     * The page's transparency group, when a transparent seal put transparency
     * on it and the page does not already declare one.
     *
     * A page that already has a /Group is left alone: the producer chose a
     * blending space and it is not the signer's to overrule.
     *
     * @throws InvalidPdfFileException
     */
    private function withTransparencyGroup(string $page, ?int $profileNumber): string
    {
        if ($profileNumber === null || str_contains($page, '/Group')) {
            return $page;
        }

        return $this->injectBeforeClose(
            $page,
            "/Group<</S/Transparency/CS[/ICCBased {$profileNumber} 0 R]>>",
        );
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
     * @param array<int, int> $offsets
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

        return "trailer\n<</Size {$size}/Root {$root} 0 R{$info}{$this->identifier($document)}"
            . $this->encryption($document)
            . "/Prev {$document->startxref}>>\n";
    }

    /**
     * The `/Encrypt` reference, repeated into this revision's trailer.
     *
     * ISO 32000-1 §7.5.5: every trailer carries it. A revision that leaves it
     * out reads as the point where the document stopped being encrypted, so a
     * reader stops decrypting and every stream written before it inflates to
     * nothing. qpdf says "incorrect header check"; a user says the file is
     * broken.
     */
    private function encryption(DocumentInfo $document): string
    {
        return $document->encryptRef === 0 ? '' : "/Encrypt {$document->encryptRef} 0 R";
    }

    /**
     * The document's /ID, carried into the revision's trailer.
     *
     * ISO 32000-1 §14.4: it identifies the file, and every trailer carries it.
     * Dropping it made a signed PDF/A document stop conforming on §6.1.3, which
     * is how this was found, and it costs a document its identity for every
     * reader besides
     * (docs/decisions/0025-what-signing-does-to-pdf-a.md).
     *
     * The pair is carried through unchanged. The second string is meant to
     * change when the file does, and inventing one here would be inventing a
     * digest no reader checks, while the first has to stay put either way.
     */
    private function identifier(DocumentInfo $document): string
    {
        return $document->id === null ? '' : "/ID {$document->id}";
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
