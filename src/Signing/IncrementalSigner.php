<?php

namespace LSNepomuceno\LaravelA1PdfSign\Signing;

use LSNepomuceno\LaravelA1PdfSign\Contracts\PdfSigner;
use LSNepomuceno\LaravelA1PdfSign\Data\Certificate;
use LSNepomuceno\LaravelA1PdfSign\Data\SealImage;
use LSNepomuceno\LaravelA1PdfSign\Data\SealPlacement;
use LSNepomuceno\LaravelA1PdfSign\Data\SignatureInfo;
use LSNepomuceno\LaravelA1PdfSign\Data\SignedPdf;
use LSNepomuceno\LaravelA1PdfSign\Enums\SignatureProfile;
use LSNepomuceno\LaravelA1PdfSign\Exceptions\InvalidPdfFileException;
use LSNepomuceno\LaravelA1PdfSign\Signing\Cades\CadesBuilder;
use LSNepomuceno\LaravelA1PdfSign\Signing\Incremental\ByteRangeCalculator;
use LSNepomuceno\LaravelA1PdfSign\Signing\Incremental\DocTimeStampWriter;
use LSNepomuceno\LaravelA1PdfSign\Signing\Incremental\DocumentReader;
use LSNepomuceno\LaravelA1PdfSign\Signing\Incremental\DssWriter;
use LSNepomuceno\LaravelA1PdfSign\Signing\Incremental\RevisionWriter;

/**
 * Signs by appending a revision, leaving the original bytes untouched.
 *
 * This is the default path, and it is what makes multiple signatures possible:
 * each one covers the file up to its own revision, so signing again does not
 * invalidate what came before. It also stops the silent damage the v1 flow
 * caused — rebuilding a document through FPDI discarded annotations, form
 * fields and any signature already present. See ARCHITECTURE-V2.md §3h.
 *
 * Proven by poc/incremental-signature: three signatures, all valid.
 */
final readonly class IncrementalSigner implements PdfSigner
{
    /**
     * Reserved size of the /Contents placeholder, in hex characters.
     *
     * tc-lib-pdf reserves 11742 bytes. This is deliberately larger: a plain
     * CMS is ~1.5 KB, but embedding the certificate chain pushes it up, and
     * overflowing the placeholder is a hard failure. See §3h risks.
     */
    private const int CONTENTS_HEX_LENGTH = 16384;

    public function __construct(
        private DocumentReader $reader,
        private RevisionWriter $writer,
        private ByteRangeCalculator $byteRange,
        private CadesBuilder $cades,
        private DssWriter $dss,
        private DocTimeStampWriter $archiveTimestamp,
    ) {}

    public function sign(
        string $pdfContents,
        Certificate $certificate,
        SignatureInfo $info,
        string $fieldName = 'Signature',
        ?SealImage $seal = null,
        ?SealPlacement $placement = null,
        ?SignatureProfile $profile = null,
    ): SignedPdf {
        $profile ??= SignatureProfile::PadesBB;

        $document = $this->reader->read($pdfContents);

        $withRevision = $this->writer->append(
            $pdfContents,
            $document,
            $info,
            self::CONTENTS_HEX_LENGTH,
            $this->uniqueFieldName($pdfContents, $fieldName),
            $seal,
            $placement,
            $profile,
        );

        $withByteRange = $this->byteRange->apply($withRevision, self::CONTENTS_HEX_LENGTH);
        $signed = $this->embedSignature($withByteRange, $certificate, $profile);

        // B-LT and above append the validation material as a further revision,
        // after the signature it vouches for is already in place.
        if ($profile->needsValidationMaterial()) {
            $signed = $this->dss->append($signed, $certificate);
        }

        // B-LTA closes with an archive timestamp over the whole file, so the
        // validation material is attested along with the signature.
        if ($profile->needsArchiveTimestamp()) {
            $signed = $this->archiveTimestamp->append($signed);
        }

        return new SignedPdf($signed);
    }

    /**
     * @throws InvalidPdfFileException
     */
    private function embedSignature(string $pdf, Certificate $certificate, SignatureProfile $profile): string
    {
        [$open, $close, $trailing] = $this->byteRange->readLast($pdf);

        $der = $this->cades->build(
            $this->byteRange->signableSpan($pdf, $open, $close, $trailing),
            $certificate,
            $profile,
        );

        $hex = bin2hex($der);

        if (strlen($hex) > self::CONTENTS_HEX_LENGTH) {
            throw new InvalidPdfFileException(sprintf(
                'the %d-byte signature does not fit the %d-byte reserved space',
                strlen($der),
                intdiv(self::CONTENTS_HEX_LENGTH, 2),
            ));
        }

        // Only the hex payload is replaced, so no offset moves and the
        // ByteRange written moments ago stays correct.
        return substr_replace(
            $pdf,
            str_pad($hex, self::CONTENTS_HEX_LENGTH, '0'),
            $open + 1,
            self::CONTENTS_HEX_LENGTH,
        );
    }

    /**
     * Signature fields must not collide, so each revision gets its own name.
     */
    private function uniqueFieldName(string $pdf, string $base): string
    {
        return $base . (preg_match_all('/\/FT\s*\/Sig/', $pdf) + 1);
    }
}
