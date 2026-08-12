<?php

declare(strict_types=1);

namespace LSNepomuceno\LaravelA1PdfSign\Signing\Incremental;

use LSNepomuceno\LaravelA1PdfSign\Enums\CertificationLevel;
use LSNepomuceno\LaravelA1PdfSign\Exceptions\InvalidPdfFileException;

/**
 * Reads the certification a document already carries, if any.
 *
 * The catalog's /Perms /DocMDP names the signature that certified the
 * document, ISO 32000-1 §12.8.2.2, and that signature's /Reference carries the
 * transform whose /P says what is permitted. Both have to be read: a /Perms
 * entry pointing at a signature with no DocMDP transform, or a transform with
 * no /Perms entry, is a document readers disagree about, and treating either
 * half as a certification would answer a question the file does not settle.
 *
 * See docs/decisions/0012-certification-signatures.md.
 *
 * @internal
 */
final readonly class CertificationReader
{
    public function __construct(
        private DocumentReader $reader,
    ) {}

    /**
     * @throws InvalidPdfFileException
     */
    public function level(string $pdf, ?DocumentInfo $document = null): ?CertificationLevel
    {
        $document ??= $this->reader->read($pdf);

        $catalog = $this->reader->rawObject($pdf, $document, $document->root);

        if (preg_match('/\/Perms\s*<<[^>]*\/DocMDP\s+(\d+)\s+\d+\s+R/', $catalog, $perms) !== 1) {
            return null;
        }

        $number = (int) $perms[1];

        if (! isset($document->xref[$number])) {
            return null;
        }

        $signature = $this->reader->rawObject($pdf, $document, $number);

        if (! str_contains($signature, '/DocMDP')) {
            return null;
        }

        if (preg_match('/\/TransformParams\s*<<[^>]*\/P\s+(\d+)/', $signature, $params) !== 1) {
            return null;
        }

        return CertificationLevel::fromPermission((int) $params[1]);
    }
}
