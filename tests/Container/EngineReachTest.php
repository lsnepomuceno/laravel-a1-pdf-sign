<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use LSNepomuceno\LaravelA1PdfSign\Facades\A1PdfSign;
use LSNepomuceno\Signet\Data\SealPlacement;
use LSNepomuceno\Signet\Enums\SignatureProfile;
use LSNepomuceno\Signet\Testing\DebugCertificate;

/**
 * What the engine can do, reached from here.
 *
 * A wrapper that exposes less than the package it wraps is a wrapper that gets
 * patched the first time somebody needs the rest, so each of these is a
 * capability signet-pdf 3.0 added and this package could not reach
 * (docs/decisions/0039-the-core-lives-in-signet-pdf.md).
 */
it('signs in two phases, with the private key outside the process', function () {
    [$certificate, $privateKey] = DebugCertificate::makePem(false);

    // Phase one: the document is digested here, and only the public half of
    // the certificate is needed to reserve the right amount of space.
    $prepared = A1PdfSign::newSignature()
        ->certificatePublic($certificate)
        ->pdf(resource('test.pdf'))
        ->prepare();

    expect($prepared->digestValue)->not->toBeEmpty();

    // Phase two: something holding the key signs the digest. Here that is
    // openssl in the test; in production it is an HSM or a remote service.
    $cms = signDigestElsewhere($prepared->digestValue, $certificate, $privateKey);

    $signed = A1PdfSign::complete($prepared, $cms);

    expect($signed->contents)->toContain('/ETSI.CAdES.detached');
});

it('adds a field through the facade, not only through the command', function () {
    $document = A1PdfSign::addSignatureField(resource('test.pdf'), 'Manager');

    $path = A1PdfSign::tempPath(true, '.pdf');
    $document->save($path);

    expect(A1PdfSign::signatureFields($path))->toHaveCount(1);

    unlink($path);
});

it('places the field where it is told', function () {
    $document = A1PdfSign::addSignatureField(
        resource('test.pdf'),
        'Visible',
        new SealPlacement(x: 60, y: 400, width: 120, height: 40),
    );

    $path = A1PdfSign::tempPath(true, '.pdf');
    $document->save($path);

    expect(A1PdfSign::signatureFields($path)[0]->isVisible())->toBeTrue();

    unlink($path);
});

it('validates and lists fields from a disk, not only from a path', function () {
    Storage::fake('documents');

    [$pfxPath, $password] = debugCertificate();

    $signed = A1PdfSign::newSignature()
        ->certificate($pfxPath, $password)
        ->pdf(resource('test.pdf'))
        ->sign();

    Storage::disk('documents')->put('signed.pdf', $signed->contents);

    $source = A1PdfSign::fromDisk('documents', 'signed.pdf');

    expect(A1PdfSign::validate($source)->signatures)->toHaveCount(1)
        ->and(A1PdfSign::signatureFields($source))->toHaveCount(1);
});

it('hands back a receipt saying what was signed', function () {
    [$pfxPath, $password] = debugCertificate();

    $signed = A1PdfSign::newSignature()
        ->certificate($pfxPath, $password)
        ->pdf(resource('test.pdf'))
        ->sign();

    // A method rather than a property, because it hashes the document: two
    // passes nobody should pay for inside sign().
    $receipt = $signed->receipt();

    // The question a caller would otherwise answer by reparsing the file:
    // what profile was this, and what did it grow by.
    expect($receipt)->not->toBeNull()
        ->and($receipt->profile)->toBe(SignatureProfile::PadesBB)
        ->and($receipt->size)->toBeGreaterThan($receipt->originalSize);
});

/**
 * Signs a digest with the private key, standing in for an HSM.
 */
function signDigestElsewhere(string $digest, string $certificate, string $privateKey): string
{
    openssl_pkcs7_sign(
        $file = tempnam(sys_get_temp_dir(), 'digest'),
        $out = tempnam(sys_get_temp_dir(), 'cms'),
        $certificate,
        $privateKey,
        [],
        PKCS7_BINARY | PKCS7_DETACHED | PKCS7_NOATTR,
    );

    file_put_contents($file, $digest);

    return (string) file_get_contents($out);
}
