<?php

declare(strict_types=1);

namespace LSNepomuceno\LaravelA1PdfSign\Testing;

use LSNepomuceno\LaravelA1PdfSign\Contracts\PdfSigner;
use LSNepomuceno\LaravelA1PdfSign\Data\{Certificate,
    FieldLock,
    SealImage,
    SealPlacement,
    SignatureInfo,
    SignedPdf};
use LSNepomuceno\LaravelA1PdfSign\Enums\{CertificationLevel, SignatureProfile};

/**
 * Records what would have been signed, and signs nothing.
 *
 * The builder is the documented way in, so faking `Contracts\A1PdfSign` alone
 * would leave `newSignature()->…->sign()` reaching the real signer. This sits
 * under it instead: `Signing\PendingSignature` depends on this contract, so
 * every route through the builder lands here.
 *
 * It never touches a certificate or a document. That is the point: a consuming
 * application testing its own signing flow should not need a PKCS#12 bundle in
 * its repository.
 */
final class FakePdfSigner implements PdfSigner
{
    /** @var list<array{document: string, fieldName: string, profile: ?SignatureProfile, certification: ?CertificationLevel, sealed: bool}> */
    public array $signed = [];

    #[\Override]
    public function sign(
        string &$pdfContents,
        Certificate $certificate,
        SignatureInfo $info,
        string $fieldName = 'Signature',
        ?SealImage $seal = null,
        ?SealPlacement $placement = null,
        ?SignatureProfile $profile = null,
        ?string $intoField = null,
        ?CertificationLevel $certification = null,
        ?FieldLock $lock = null,
        #[\SensitiveParameter]
        string $documentPassword = '',
    ): SignedPdf {
        $this->signed[] = [
            'document' => $pdfContents,
            'fieldName' => $fieldName,
            'profile' => $profile,
            'certification' => $certification,
            'sealed' => $seal !== null,
        ];

        // Something the calling code can use: it will read ->contents, call
        // ->save() or ->size(), and a null would fail somewhere unhelpful.
        return new SignedPdf("%PDF-1.4\n% faked by " . self::class . "\n%%EOF\n");
    }
}
