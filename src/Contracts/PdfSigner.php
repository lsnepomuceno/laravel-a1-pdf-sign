<?php

namespace LSNepomuceno\LaravelA1PdfSign\Contracts;

use LSNepomuceno\LaravelA1PdfSign\Data\Certificate;
use LSNepomuceno\LaravelA1PdfSign\Data\FieldLock;
use LSNepomuceno\LaravelA1PdfSign\Data\SealImage;
use LSNepomuceno\LaravelA1PdfSign\Data\SealPlacement;
use LSNepomuceno\LaravelA1PdfSign\Data\SignatureInfo;
use LSNepomuceno\LaravelA1PdfSign\Data\SignedPdf;
use LSNepomuceno\LaravelA1PdfSign\Enums\CertificationLevel;
use LSNepomuceno\LaravelA1PdfSign\Enums\SignatureProfile;

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
     * @param  SealImage|null  $seal  Rendered seal; null leaves the signature
     *                                invisible.
     * @param  string|null  $intoField  Fills a field the document already
     *                                  carries, instead of creating one. The
     *                                  field's own rectangle then decides where
     *                                  the seal goes and whether there is one,
     *                                  so $placement is ignored and $fieldName
     *                                  with it. A field that is missing or
     *                                  already signed is an error rather than a
     *                                  fallback to appending.
     *
     * @param  CertificationLevel|null  $certification  Makes this a
     *                                  certification signature rather than an
     *                                  approval one. It has to be the first
     *                                  signature, there can be only one, and at
     *                                  no-changes the document cannot be signed
     *                                  afterwards at all.
     *
     * @throws \LSNepomuceno\LaravelA1PdfSign\Exceptions\CertificationException
     * @throws \LSNepomuceno\LaravelA1PdfSign\Exceptions\FieldLockException
     * @throws \LSNepomuceno\LaravelA1PdfSign\Exceptions\InvalidPdfFileException
     * @throws \LSNepomuceno\LaravelA1PdfSign\Exceptions\SealPlacementException
     * @throws \LSNepomuceno\LaravelA1PdfSign\Exceptions\SignatureFieldException
     */
    public function sign(
        string $pdfContents,
        Certificate $certificate,
        SignatureInfo $info,
        string $fieldName = 'Signature',
        ?SealImage $seal = null,
        ?SealPlacement $placement = null,
        ?SignatureProfile $profile = null,
        ?string $intoField = null,
        ?CertificationLevel $certification = null,
        ?FieldLock $lock = null,
    ): SignedPdf;
}
