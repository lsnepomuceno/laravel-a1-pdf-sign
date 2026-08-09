<?php

use LSNepomuceno\LaravelA1PdfSign\Contracts\SignatureValidator;
use LSNepomuceno\LaravelA1PdfSign\Enums\CertificationLevel;
use LSNepomuceno\LaravelA1PdfSign\Exceptions\CertificationException;
use LSNepomuceno\LaravelA1PdfSign\Facades\A1PdfSign;
use LSNepomuceno\LaravelA1PdfSign\Signing\Incremental\CertificationReader;
use LSNepomuceno\LaravelA1PdfSign\Support\Files;

/**
 * Certification signatures and /DocMDP, ISO 32000-1 §12.8.2.2.
 *
 * These assert the bytes are written and the rules are enforced. They cannot
 * assert that a reader honours the certification, which is precisely what
 * varies between readers: see docs/decisions/0012-certification-signatures.md.
 */
function certified(CertificationLevel|string $level = CertificationLevel::FormFilling): string
{
    [$pfxPath, $password] = debugCertificate();

    return A1PdfSign::newSignature()
        ->certificate($pfxPath, $password)
        ->pdf(resource('test.pdf'))
        ->certify($level)
        ->sign()
        ->contents;
}

it('writes the DocMDP transform and the permission that names it', function (
    CertificationLevel $level,
    int $permission,
) {
    $pdf = certified($level);

    // Both halves, or neither. A /Perms pointing at a signature with no
    // transform, or a transform with no /Perms, is a document readers disagree
    // about.
    expect($pdf)->toContain("/TransformParams<</Type/TransformParams/P {$permission}/V/1.2>>")
        ->and($pdf)->toContain('/TransformMethod/DocMDP')
        ->and($pdf)->toMatch('#/Perms<</DocMDP (\d+) 0 R>>#');

    $number = preg_match('#/Perms<</DocMDP (\d+) 0 R>>#', $pdf, $perms) === 1 ? $perms[1] : '';

    // The entry has to name the signature that actually carries the transform,
    // not merely some signature.
    expect($number)->not->toBe('')
        ->and($pdf)->toContain("{$number} 0 obj\n<</Type/Sig");
})->with([
    'no changes' => [CertificationLevel::NoChanges, 1],
    'form filling' => [CertificationLevel::FormFilling, 2],
    'annotations' => [CertificationLevel::Annotations, 3],
]);

it('reads back the level it wrote', function (CertificationLevel $level) {
    expect(app(CertificationReader::class)->level(certified($level)))->toBe($level);
})->with([
    'no changes' => [CertificationLevel::NoChanges],
    'form filling' => [CertificationLevel::FormFilling],
    'annotations' => [CertificationLevel::Annotations],
]);

it('reports the certification through the validator', function () {
    $report = app(SignatureValidator::class)->validate(certified(CertificationLevel::FormFilling));

    expect($report->isCertified())->toBeTrue()
        ->and($report->certification)->toBe(CertificationLevel::FormFilling)
        ->and($report->acceptsFurtherSignatures())->toBeTrue()
        ->and($report->isValid())->toBeTrue();
});

it('reports no certification for an ordinary approval signature', function () {
    [$pfxPath, $password] = debugCertificate();

    $signed = A1PdfSign::newSignature()
        ->certificate($pfxPath, $password)
        ->pdf(resource('test.pdf'))
        ->sign();

    $report = app(SignatureValidator::class)->validate($signed->contents);

    expect($report->isCertified())->toBeFalse()
        ->and($report->certification)->toBeNull()
        // An uncertified document restricts nothing, so it accepts more.
        ->and($report->acceptsFurtherSignatures())->toBeTrue()
        // The signature is still valid: a certification is a further claim, not
        // a precondition.
        ->and($report->isValid())->toBeTrue();
});

it('refuses to sign a document certified as no-changes', function () {
    // The exclusion this record exists to make obvious rather than
    // discoverable. A further signature is a further revision, which is exactly
    // what /P 1 forbids.
    [$pfxPath, $password] = debugCertificate();

    expect(fn() => A1PdfSign::newSignature()
        ->certificate($pfxPath, $password)
        ->pdfContents(certified(CertificationLevel::NoChanges), 'locked.pdf')
        ->sign())
        ->toThrow(CertificationException::class, 'forbids the further revision');
});

it('lets a document certified as form-filling be signed afterwards', function () {
    // This is what the level is for, so the refusal above must not be blanket.
    [$pfxPath, $password] = debugCertificate();

    $signed = A1PdfSign::newSignature()
        ->certificate($pfxPath, $password)
        ->pdfContents(certified(CertificationLevel::FormFilling), 'contract.pdf')
        ->info(name: 'Second signer')
        ->sign();

    $report = app(SignatureValidator::class)->validate($signed->contents);

    expect($report->signatures)->toHaveCount(2)
        ->and($report->isValid())->toBeTrue()
        // The certification survives the approval signature that followed it.
        ->and($report->certification)->toBe(CertificationLevel::FormFilling);
});

it('refuses a second certification', function () {
    [$pfxPath, $password] = debugCertificate();

    expect(fn() => A1PdfSign::newSignature()
        ->certificate($pfxPath, $password)
        ->pdfContents(certified(CertificationLevel::FormFilling), 'contract.pdf')
        ->certify(CertificationLevel::Annotations)
        ->sign())
        ->toThrow(CertificationException::class, 'already certified as "form-filling"');
});

it('refuses to certify a document that is already signed', function () {
    // A certification states what may happen from here on, and an approval
    // signature already applied is a thing that happened.
    [$pfxPath, $password] = debugCertificate();

    $signed = A1PdfSign::newSignature()
        ->certificate($pfxPath, $password)
        ->pdf(resource('test.pdf'))
        ->sign();

    expect(fn() => A1PdfSign::newSignature()
        ->certificate($pfxPath, $password)
        ->pdfContents($signed->contents, 'contract.pdf')
        ->certify()
        ->sign())
        ->toThrow(CertificationException::class, 'already carries 1');
});

it('certifies at form-filling by default, since no-changes would refuse the next signer', function () {
    expect(app(CertificationReader::class)->level(certified()))->toBe(CertificationLevel::FormFilling);
});

it('accepts the level as a configuration string', function () {
    expect(app(CertificationReader::class)->level(certified('annotations')))
        ->toBe(CertificationLevel::Annotations);
});

it('falls back to the most restrictive level when the string means nothing', function () {
    // An unreadable value must not quietly become the most permissive one.
    expect(CertificationLevel::resolve('whatever-this-is'))->toBe(CertificationLevel::NoChanges);
});

it('maps each level to the permission the transform carries', function () {
    expect(CertificationLevel::NoChanges->permission())->toBe(1)
        ->and(CertificationLevel::FormFilling->permission())->toBe(2)
        ->and(CertificationLevel::Annotations->permission())->toBe(3)
        ->and(CertificationLevel::NoChanges->allowsFurtherSignatures())->toBeFalse()
        ->and(CertificationLevel::FormFilling->allowsFurtherSignatures())->toBeTrue()
        ->and(CertificationLevel::Annotations->allowsFurtherSignatures())->toBeTrue();
});

it('reads a permission back into a level, and refuses one the standard does not define', function () {
    expect(CertificationLevel::fromPermission(1))->toBe(CertificationLevel::NoChanges)
        ->and(CertificationLevel::fromPermission(2))->toBe(CertificationLevel::FormFilling)
        ->and(CertificationLevel::fromPermission(3))->toBe(CertificationLevel::Annotations)
        ->and(CertificationLevel::fromPermission(0))->toBeNull()
        ->and(CertificationLevel::fromPermission(4))->toBeNull();
});

it('reports no certification when the document carries none', function () {
    expect(app(CertificationReader::class)->level(Files::read(resource('test.pdf'))))->toBeNull();
});

it('ignores a /Perms that names a signature carrying no transform', function () {
    // Half a certification is not a certification: answering otherwise would
    // settle a question the file leaves open.
    $pdf = pdfWith([
        1 => '<</Type/Catalog/Pages 2 0 R/Perms<</DocMDP 3 0 R>>>>',
        2 => '<</Type/Pages/Kids[]/Count 0>>',
        3 => '<</Type/Sig/Filter/Adobe.PPKLite/SubFilter/ETSI.CAdES.detached>>',
    ]);

    expect(app(CertificationReader::class)->level($pdf))->toBeNull();
});

it('ignores a /Perms that names an object the document does not have', function () {
    $pdf = pdfWith([
        1 => '<</Type/Catalog/Pages 2 0 R/Perms<</DocMDP 9 0 R>>>>',
        2 => '<</Type/Pages/Kids[]/Count 0>>',
    ]);

    expect(app(CertificationReader::class)->level($pdf))->toBeNull();
});

it('ignores a transform whose permission the standard does not define', function () {
    $pdf = pdfWith([
        1 => '<</Type/Catalog/Pages 2 0 R/Perms<</DocMDP 3 0 R>>>>',
        2 => '<</Type/Pages/Kids[]/Count 0>>',
        3 => '<</Type/Sig/Reference[<</TransformMethod/DocMDP/TransformParams<</P 7/V/1.2>>>>]>>',
    ]);

    expect(app(CertificationReader::class)->level($pdf))->toBeNull();
});

it('leaves an uncertified document without a /Perms entry at all', function () {
    [$pfxPath, $password] = debugCertificate();

    $signed = A1PdfSign::newSignature()
        ->certificate($pfxPath, $password)
        ->pdf(resource('test.pdf'))
        ->sign();

    expect($signed->contents)->not->toContain('/Perms')
        ->and($signed->contents)->not->toContain('/DocMDP');
});

it('does not read a FieldMDP transform as a certification', function () {
    // FieldMDP (§12.8.2.4) locks named fields; DocMDP (§12.8.2.2) certifies the
    // document. They carry the same /TransformParams /P, so reading the
    // parameters without checking the method would report a field lock as a
    // document certification.
    $pdf = pdfWith([
        1 => '<</Type/Catalog/Pages 2 0 R/Perms<</DocMDP 3 0 R>>>>',
        2 => '<</Type/Pages/Kids[]/Count 0>>',
        3 => '<</Type/Sig/Reference[<</TransformMethod/FieldMDP/TransformParams<</P 2/V/1.2>>>>]>>',
    ]);

    expect(app(CertificationReader::class)->level($pdf))->toBeNull();
});

it('ignores a DocMDP transform that declares no permission', function () {
    // /P is what says which of the three levels this is, so a transform without
    // one settles nothing.
    $pdf = pdfWith([
        1 => '<</Type/Catalog/Pages 2 0 R/Perms<</DocMDP 3 0 R>>>>',
        2 => '<</Type/Pages/Kids[]/Count 0>>',
        3 => '<</Type/Sig/Reference[<</TransformMethod/DocMDP/TransformParams<</V/1.2>>>>]>>',
    ]);

    expect(app(CertificationReader::class)->level($pdf))->toBeNull();
});
