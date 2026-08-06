<?php

namespace LSNepomuceno\LaravelA1PdfSign\Validation;

use Illuminate\Support\Facades\File;
use LSNepomuceno\LaravelA1PdfSign\Contracts\SignatureValidator;
use LSNepomuceno\LaravelA1PdfSign\Data\SignatureDetails;
use LSNepomuceno\LaravelA1PdfSign\Data\SignatureReport;
use LSNepomuceno\LaravelA1PdfSign\Exceptions\FileNotFoundException;
use LSNepomuceno\LaravelA1PdfSign\Exceptions\HasNoSignatureOrInvalidPkcs7Exception;
use LSNepomuceno\LaravelA1PdfSign\Exceptions\InvalidPdfFileException;

/**
 * Reports on every signature in a document.
 */
final readonly class PdfSignatureValidator implements SignatureValidator
{
    public function __construct(
        private PdfSignatureExtractor $extractor,
        private Pkcs7Reader $reader,
        private SignatureVerifier $verifier,
    ) {}

    /**
     * @throws FileNotFoundException
     * @throws InvalidPdfFileException
     * @throws HasNoSignatureOrInvalidPkcs7Exception
     */
    public function validateFile(string $pdfPath): SignatureReport
    {
        if (! str_ends_with(strtolower($pdfPath), '.pdf')) {
            throw new InvalidPdfFileException($pdfPath);
        }

        if (! File::exists($pdfPath)) {
            throw new FileNotFoundException($pdfPath);
        }

        return $this->validate(File::get($pdfPath), $pdfPath);
    }

    /**
     * @throws HasNoSignatureOrInvalidPkcs7Exception
     */
    public function validate(string $pdfContents, string $label = 'the document'): SignatureReport
    {
        $extracted = $this->extractor->extract($pdfContents);

        if ($extracted === []) {
            throw new HasNoSignatureOrInvalidPkcs7Exception($label);
        }

        $size = strlen($pdfContents);
        $signatures = [];

        foreach ($extracted as $signature) {
            [$open, $close, $trailing] = $signature['byteRange'];

            $signatures[] = new SignatureDetails(
                verified: $this->verifier->verify(
                    $signature['cms'],
                    $this->extractor->coveredBytes($pdfContents, $open, $close, $trailing),
                ),
                signers: $this->reader->signers($signature['cms']),
                coverageEnd: $signature['coverageEnd'],
                // Only the last signature reaches the end of the file; the
                // earlier ones stop at the revision they were written into,
                // which is expected rather than a defect.
                coversWholeDocument: $signature['coverageEnd'] === $size,
            );
        }

        return new SignatureReport($signatures);
    }
}
