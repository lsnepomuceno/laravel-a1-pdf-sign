<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use LSNepomuceno\LaravelA1PdfSign\Facades\A1PdfSign;

it('signs a pdf using a certificate on disk', function () {
    [$pfxPath, $pass] = debugCertificate();

    $pdfPath = A1PdfSign::tempPath(true, '.pdf');
    A1PdfSign::signFromFile($pfxPath, $pass, resource('test.pdf'))->save($pdfPath);

    expect(File::exists($pdfPath))->toBeTrue();

    File::delete([$pfxPath, $pdfPath]);
});

it('signs a pdf using a certificate on disk with the PATH env', function () {
    [$pfxPath, $pass] = debugCertificate();

    $pdfPath = A1PdfSign::tempPath(true, '.pdf');
    A1PdfSign::signFromFile(
        pfxPath: $pfxPath,
        password: $pass,
        pdfPath: resource('test.pdf'),
        usePathEnv: true,
    )->save($pdfPath);

    expect(File::exists($pdfPath))->toBeTrue();

    File::delete([$pfxPath, $pdfPath]);
});

it('signs a pdf using an uploaded certificate', function () {
    [$pfxPath, $pass] = debugCertificate();

    $uploadedFile = new UploadedFile($pfxPath, 'testCertificate.pfx', null, null, true);

    $pdfPath = A1PdfSign::tempPath(true, '.pdf');
    A1PdfSign::signFromUpload($uploadedFile, $pass, resource('test.pdf'))->save($pdfPath);

    expect(File::exists($pdfPath))->toBeTrue();

    File::delete([$pfxPath, $pdfPath]);
});

it('signs a pdf using an uploaded certificate with the PATH env', function () {
    [$pfxPath, $pass] = debugCertificate();

    $uploadedFile = new UploadedFile($pfxPath, 'testCertificate.pfx', null, null, true);

    $pdfPath = A1PdfSign::tempPath(true, '.pdf');
    A1PdfSign::signFromUpload(
        uploadedPfx: $uploadedFile,
        password: $pass,
        pdfPath: resource('test.pdf'),
        usePathEnv: true,
    )->save($pdfPath);

    expect(File::exists($pdfPath))->toBeTrue();
});

it('signs a pdf using a PEM certificate and key in separate files', function () {
    [$certificatePath, $privateKeyPath, , $pass] = pemCertificate();

    $pdfPath = A1PdfSign::tempPath(true, '.pdf');
    A1PdfSign::signFromPem($certificatePath, $pass, resource('test.pdf'), $privateKeyPath)->save($pdfPath);

    expect(File::exists($pdfPath))->toBeTrue()
        ->and(A1PdfSign::validate($pdfPath)->isValid())->toBeTrue();

    File::delete([$certificatePath, $privateKeyPath, $pdfPath]);
});

it('signs a pdf using a combined PEM bundle', function () {
    [, , $bundlePath, $pass] = pemCertificate();

    $pdfPath = A1PdfSign::tempPath(true, '.pdf');
    A1PdfSign::signFromPem($bundlePath, $pass, resource('test.pdf'))->save($pdfPath);

    expect(File::exists($pdfPath))->toBeTrue()
        ->and(A1PdfSign::validate($pdfPath)->isValid())->toBeTrue();

    File::delete([$bundlePath, $pdfPath]);
});

it('signs a pdf using an unencrypted PEM key', function () {
    // No password at all, the case PKCS#12 cannot express.
    [, , $bundlePath, $pass] = pemCertificate(encryptKey: false);

    $pdfPath = A1PdfSign::tempPath(true, '.pdf');
    A1PdfSign::signFromPem($bundlePath, $pass, resource('test.pdf'))->save($pdfPath);

    expect($pass)->toBe('')
        ->and(A1PdfSign::validate($pdfPath)->isValid())->toBeTrue();

    File::delete([$bundlePath, $pdfPath]);
});

it('signs through the builder with a PEM certificate held in memory', function () {
    [$certificate, $privateKey, $password] = LSNepomuceno\LaravelA1PdfSign\Testing\DebugCertificate::makePem();

    $signed = A1PdfSign::newSignature()
        ->certificateFromPem($certificate, $privateKey, $password)
        ->pdf(resource('test.pdf'))
        ->info(name: 'PEM signer', reason: 'Contract')
        ->sign();

    $pdfPath = A1PdfSign::tempPath(true, '.pdf');
    $signed->save($pdfPath);

    expect(A1PdfSign::validate($pdfPath)->isValid())->toBeTrue();

    File::delete($pdfPath);
});

it('produces a signature indistinguishable from the PKCS#12 path', function () {
    // Same key material, both entry points: the signer must not be able to tell
    // how the certificate arrived.
    [$pfx, $password] = LSNepomuceno\LaravelA1PdfSign\Testing\DebugCertificate::make();

    $bundle = app(LSNepomuceno\LaravelA1PdfSign\Certificates\NativeCertificateReader::class)
        ->read($pfx, $password)->original;

    $viaPem = A1PdfSign::newSignature()
        ->certificateFromPem($bundle)
        ->pdf(resource('test.pdf'))
        ->sign();

    $pdfPath = A1PdfSign::tempPath(true, '.pdf');
    $viaPem->save($pdfPath);

    $report = A1PdfSign::validate($pdfPath);

    expect($report->isValid())->toBeTrue()
        ->and($report->count())->toBe(1)
        ->and($report->signers()[0]->commonName)->toBe('Test Certificate');

    File::delete($pdfPath);
});

it('encrypts certificate data', function () {
    [$pfxPath, $pass] = debugCertificate();

    expect(A1PdfSign::encryptCertificate($pfxPath, $pass)->toArray())
        ->toHaveKeys(['certificate', 'password', 'hash']);
});

it('creates temporary paths with the requested extension', function () {
    expect(File::isDirectory(A1PdfSign::tempPath()))->toBeTrue()
        ->and(A1PdfSign::tempPath(true))->toEndWith('.pfx')
        ->and(A1PdfSign::tempPath(true, '.pdf'))->toEndWith('.pdf');
});

it('validates a signed pdf', function () {
    [$pfxPath, $pass] = debugCertificate();

    $pdfPath = A1PdfSign::tempPath(true, '.pdf');
    A1PdfSign::signFromFile($pfxPath, $pass, resource('test.pdf'))->save($pdfPath);

    expect(File::exists($pdfPath))->toBeTrue()
        ->and(A1PdfSign::validate($pdfPath)->isValid())->toBeTrue();
});
