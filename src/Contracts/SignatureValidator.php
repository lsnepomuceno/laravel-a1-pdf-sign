<?php

namespace LSNepomuceno\LaravelA1PdfSign\Contracts;

use LSNepomuceno\LaravelA1PdfSign\Data\SignatureReport;
use LSNepomuceno\LaravelA1PdfSign\Validation\TrustStore;

/**
 * Inspects the signatures embedded in a PDF.
 *
 * A trust store is optional and the answer is tri-state: with one, each
 * signature reports whether it chains to an authority the caller trusts;
 * without one, trust is null rather than false, because a question nobody put
 * has no answer (docs/decisions/0016-trust-is-the-applications-policy.md).
 */
interface SignatureValidator
{
    /**
     * @throws \LSNepomuceno\LaravelA1PdfSign\Exceptions\FileNotFoundException
     * @throws \LSNepomuceno\LaravelA1PdfSign\Exceptions\InvalidPdfFileException
     * @throws \LSNepomuceno\LaravelA1PdfSign\Exceptions\HasNoSignatureOrInvalidPkcs7Exception
     */
    public function validateFile(string $pdfPath, ?TrustStore $trust = null): SignatureReport;

    /**
     * @throws \LSNepomuceno\LaravelA1PdfSign\Exceptions\HasNoSignatureOrInvalidPkcs7Exception
     */
    public function validate(string $pdfContents, string $label = 'the document', ?TrustStore $trust = null): SignatureReport;
}
