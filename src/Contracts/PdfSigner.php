<?php

namespace LSNepomuceno\LaravelA1PdfSign\Contracts;

use LSNepomuceno\LaravelA1PdfSign\Data\Certificate;
use LSNepomuceno\LaravelA1PdfSign\Data\SignatureInfo;
use LSNepomuceno\LaravelA1PdfSign\Data\SignedPdf;

/**
 * Signs an existing PDF.
 */
interface PdfSigner
{
    /**
     * @param  string  $pdfContents  The document to sign, as bytes.
     * @param  string  $fieldName  Name of the signature field. Must be unique
     *                             within the document, so successive signers
     *                             occupy separate fields.
     *
     * @throws \LSNepomuceno\LaravelA1PdfSign\Exceptions\InvalidPdfFileException
     */
    public function sign(
        string $pdfContents,
        Certificate $certificate,
        SignatureInfo $info,
        string $fieldName = 'Signature',
    ): SignedPdf;
}
