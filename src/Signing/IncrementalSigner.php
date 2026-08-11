<?php

namespace LSNepomuceno\LaravelA1PdfSign\Signing;

use LSNepomuceno\LaravelA1PdfSign\Contracts\PdfSigner;
use LSNepomuceno\LaravelA1PdfSign\Data\Certificate;
use LSNepomuceno\LaravelA1PdfSign\Data\FieldLock;
use LSNepomuceno\LaravelA1PdfSign\Data\SealImage;
use LSNepomuceno\LaravelA1PdfSign\Data\SealPlacement;
use LSNepomuceno\LaravelA1PdfSign\Data\SignatureField;
use LSNepomuceno\LaravelA1PdfSign\Data\SignatureInfo;
use LSNepomuceno\LaravelA1PdfSign\Data\SignedPdf;
use LSNepomuceno\LaravelA1PdfSign\Enums\CertificationLevel;
use LSNepomuceno\LaravelA1PdfSign\Enums\SignatureProfile;
use LSNepomuceno\LaravelA1PdfSign\Exceptions\CertificationException;
use LSNepomuceno\LaravelA1PdfSign\Exceptions\FieldLockException;
use LSNepomuceno\LaravelA1PdfSign\Exceptions\InvalidPdfFileException;
use LSNepomuceno\LaravelA1PdfSign\Exceptions\SignatureFieldException;
use LSNepomuceno\LaravelA1PdfSign\Signing\Cades\CadesBuilder;
use LSNepomuceno\LaravelA1PdfSign\Signing\Incremental\ByteRangeCalculator;
use LSNepomuceno\LaravelA1PdfSign\Signing\Incremental\CertificationReader;
use LSNepomuceno\LaravelA1PdfSign\Signing\Incremental\DocTimeStampWriter;
use LSNepomuceno\LaravelA1PdfSign\Signing\Incremental\DocumentInfo;
use LSNepomuceno\LaravelA1PdfSign\Signing\Incremental\DocumentReader;
use LSNepomuceno\LaravelA1PdfSign\Signing\Incremental\DssWriter;
use LSNepomuceno\LaravelA1PdfSign\Signing\Incremental\FieldLockReader;
use LSNepomuceno\LaravelA1PdfSign\Signing\Incremental\RevisionWriter;
use LSNepomuceno\LaravelA1PdfSign\Signing\Incremental\SignatureFieldReader;

/**
 * Signs by appending a revision, leaving the original bytes untouched.
 *
 * This is the default path, and it is what makes multiple signatures possible:
 * each one covers the file up to its own revision, so signing again does not
 * invalidate what came before. It also stops the silent damage the v1 flow
 * caused: rebuilding a document through FPDI discarded annotations, form
 * fields and any signature already present. See docs/decisions/0006-incremental-revision.md.
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
        // Defaulted, not required. 2.2 added both as required parameters and
        // so raised the constructor's arity from six to eight, which breaks
        // anyone who builds this by hand rather than through the container.
        // The Roave check caught it on its first run; nothing in the suite
        // could have, because the suite resolves everything from the container
        // (docs/spec/quality-policy.md).
        private SignatureFieldReader $fields = new SignatureFieldReader(new DocumentReader()),
        private CertificationReader $certifications = new CertificationReader(new DocumentReader()),
        // Appended, so the arity a hand-built signer relies on does not move
        // (docs/decisions/0021-locking-fields-and-honouring-locks.md).
        private FieldLockReader $locks = new FieldLockReader(new DocumentReader()),
    ) {}

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
    ): SignedPdf {
        $profile ??= SignatureProfile::PadesBB;

        $document = $this->reader->read($pdfContents);

        $this->guardCertification($pdfContents, $document, $certification);

        $this->guardLock($lock);

        $target = $intoField === null ? null : $this->target($pdfContents, $document, $intoField);

        // An earlier signature may have locked the field being filled, and
        // filling it anyway breaks that signature rather than this one.
        if ($target !== null) {
            $this->guardFieldLocks($pdfContents, $document, $target->name);
        }

        // A pre-placed field already says where the seal goes: the template drew
        // the box, which is the reason the caller chose the field.
        if ($target !== null) {
            $placement = $seal !== null && $target->isVisible() ? $target->placement() : null;
        }

        $withRevision = $this->writer->append(
            $pdfContents,
            $document,
            $info,
            self::CONTENTS_HEX_LENGTH,
            $target === null ? $this->uniqueFieldName($pdfContents, $fieldName) : $target->name,
            $seal,
            $placement,
            $profile,
            $target,
            $certification,
            $lock,
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
        $open = $this->byteRange->lastContentsOffset($pdf);

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
     * A lock that would lock nothing, or everything by accident.
     *
     * /Include with no fields locks nothing and /Exclude with no fields locks
     * every field there is. Neither is plausibly what was meant, and the second
     * is the more expensive to find out about later
     * (docs/decisions/0021-locking-fields-and-honouring-locks.md).
     *
     * @throws FieldLockException
     */
    private function guardLock(?FieldLock $lock): void
    {
        if ($lock !== null && $lock->action->needsFields() && $lock->fields === []) {
            throw FieldLockException::needsFields($lock->action->value);
        }
    }

    /**
     * Refuses to fill a field an earlier signature locked.
     *
     * The alternative is producing a document whose earlier signature every
     * reader reports as broken, which the caller then discovers from the reader.
     *
     * @throws FieldLockException
     * @throws InvalidPdfFileException
     */
    private function guardFieldLocks(string $pdf, DocumentInfo $document, string $name): void
    {
        $by = $this->locks->lockOn($pdf, $name, $document);

        if ($by !== null) {
            throw FieldLockException::locked($name, $by);
        }
    }

    /**
     * The three rules of ISO 32000-1 §12.8.2.2 this package enforces rather
     * than documents.
     *
     * A caller who discovers them by watching a second signature silently
     * invalidate the first has been told too late, and the file is already
     * wrong (docs/decisions/0012-certification-signatures.md).
     *
     * @throws CertificationException
     * @throws InvalidPdfFileException
     */
    private function guardCertification(
        string $pdf,
        DocumentInfo $document,
        ?CertificationLevel $certification,
    ): void {
        $existing = $this->certifications->level($pdf, $document);

        // Applies to every signature, certification or not: at "no-changes" a
        // further revision is exactly what was forbidden.
        if ($existing !== null && ! $existing->allowsFurtherSignatures()) {
            throw CertificationException::locked();
        }

        if ($certification === null) {
            return;
        }

        if ($existing !== null) {
            throw CertificationException::alreadyCertified($existing);
        }

        $signatures = count(array_filter(
            $this->fields->read($pdf, $document),
            static fn(SignatureField $field): bool => $field->isSigned,
        ));

        // A certification states what may happen to the document from here on,
        // and an approval signature already applied is a thing that happened.
        if ($signatures > 0) {
            throw CertificationException::documentAlreadySigned($signatures);
        }
    }

    /**
     * The field to fill, or a refusal naming why it cannot be.
     *
     * Neither case falls back to appending a field beside the one asked for.
     * That fallback is the failure intoField() exists to prevent, and it would
     * happen quietly: a valid signature in the wrong place, and the template's
     * own field still empty
     * (docs/decisions/0013-signing-into-an-existing-field.md).
     *
     * @throws InvalidPdfFileException
     * @throws SignatureFieldException
     */
    private function target(string $pdf, DocumentInfo $document, string $name): SignatureField
    {
        $field = $this->fields->named($pdf, $name, $document);

        if ($field === null) {
            throw SignatureFieldException::missing(
                $name,
                array_map(static fn(SignatureField $one): string => $one->name, $this->fields->read($pdf, $document)),
            );
        }

        if ($field->isSigned) {
            throw SignatureFieldException::alreadySigned($name);
        }

        return $field;
    }

    /**
     * Signature fields must not collide, so each revision gets its own name.
     */
    private function uniqueFieldName(string $pdf, string $base): string
    {
        return $base . ($this->signatureCount($pdf) + 1);
    }

    /**
     * How many signature fields the document already carries.
     */
    private function signatureCount(string $pdf): int
    {
        $count = preg_match_all('/\/FT\s*\/Sig/', $pdf);

        return $count === false ? 0 : $count;
    }
}
