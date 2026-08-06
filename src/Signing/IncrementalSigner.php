<?php

namespace LSNepomuceno\LaravelA1PdfSign\Signing;

use LSNepomuceno\LaravelA1PdfSign\Contracts\PdfSigner;
use LSNepomuceno\LaravelA1PdfSign\Data\Certificate;
use LSNepomuceno\LaravelA1PdfSign\Data\SealImage;
use LSNepomuceno\LaravelA1PdfSign\Data\SealPlacement;
use LSNepomuceno\LaravelA1PdfSign\Data\SignatureInfo;
use LSNepomuceno\LaravelA1PdfSign\Data\SignedPdf;
use LSNepomuceno\LaravelA1PdfSign\Exceptions\InvalidPdfFileException;
use LSNepomuceno\LaravelA1PdfSign\Signing\Incremental\ByteRangeCalculator;
use LSNepomuceno\LaravelA1PdfSign\Signing\Incremental\DocumentReader;
use LSNepomuceno\LaravelA1PdfSign\Signing\Incremental\RevisionWriter;
use LSNepomuceno\LaravelA1PdfSign\Support\TemporaryFile;

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
    ) {}

    public function sign(
        string $pdfContents,
        Certificate $certificate,
        SignatureInfo $info,
        string $fieldName = 'Signature',
        ?SealImage $seal = null,
        ?SealPlacement $placement = null,
    ): SignedPdf {
        $document = $this->reader->read($pdfContents);

        $withRevision = $this->writer->append(
            $pdfContents,
            $document,
            $info,
            self::CONTENTS_HEX_LENGTH,
            $this->uniqueFieldName($pdfContents, $fieldName),
            $seal,
            $placement,
        );

        $withByteRange = $this->byteRange->apply($withRevision, self::CONTENTS_HEX_LENGTH);

        return new SignedPdf($this->embedSignature($withByteRange, $certificate));
    }

    /**
     * @throws InvalidPdfFileException
     */
    private function embedSignature(string $pdf, Certificate $certificate): string
    {
        [$open, $close, $trailing] = $this->byteRange->readLast($pdf);

        $der = $this->detachedCms(
            $this->byteRange->signableSpan($pdf, $open, $close, $trailing),
            $certificate,
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
     * Produces the detached PKCS#7 blob through ext-openssl — no shell-out.
     *
     * @throws InvalidPdfFileException
     */
    private function detachedCms(string $data, Certificate $certificate): string
    {
        $directory = sys_get_temp_dir();

        return TemporaryFile::with($directory, '.dat', $data, function (TemporaryFile $input) use ($directory, $certificate): string {
            return TemporaryFile::with($directory, '.p7s', '', function (TemporaryFile $output) use ($input, $certificate): string {
                $signed = openssl_pkcs7_sign(
                    $input->path,
                    $output->path,
                    $certificate->original,
                    [$certificate->original, $certificate->password],
                    [],
                    PKCS7_BINARY | PKCS7_DETACHED,
                );

                if (! $signed) {
                    throw new InvalidPdfFileException('openssl_pkcs7_sign failed: ' . (openssl_error_string() ?: 'unknown error'));
                }

                return $this->extractDer($output->contents());
            });
        });
    }

    /**
     * Pulls the DER out of the S/MIME envelope openssl_pkcs7_sign() writes.
     *
     * @throws InvalidPdfFileException
     */
    private function extractDer(string $smime): string
    {
        $pattern = '/Content-Type:\s*application\/x-pkcs7-signature.*?\r?\n\r?\n(.*?)\r?\n-{2,}/s';

        if (! preg_match($pattern, $smime, $matches)) {
            throw new InvalidPdfFileException('no PKCS#7 block in the S/MIME output');
        }

        $der = base64_decode((string) preg_replace('/\s+/', '', $matches[1]), true);

        if ($der === false || $der === '') {
            throw new InvalidPdfFileException('the PKCS#7 payload is not valid base64');
        }

        return $der;
    }

    /**
     * Signature fields must not collide, so each revision gets its own name.
     */
    private function uniqueFieldName(string $pdf, string $base): string
    {
        return $base . (preg_match_all('/\/FT\s*\/Sig/', $pdf) + 1);
    }
}
