<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use LSNepomuceno\LaravelA1PdfSign\Facades\A1PdfSign;

it('signs a pdf using a certificate on disk', function () {
    [$pfxPath, $pass] = debugCertificate();

    $pdfPath = A1PdfSign::tempPath(true, '.pdf');
    File::put($pdfPath, A1PdfSign::signFromFile($pfxPath, $pass, resource('test.pdf')));

    expect(File::exists($pdfPath))->toBeTrue();

    File::delete([$pfxPath, $pdfPath]);
});

it('signs a pdf using a certificate on disk with the PATH env', function () {
    [$pfxPath, $pass] = debugCertificate();

    $pdfPath = A1PdfSign::tempPath(true, '.pdf');
    File::put($pdfPath, A1PdfSign::signFromFile(
        pfxPath: $pfxPath,
        password: $pass,
        pdfPath: resource('test.pdf'),
        usePathEnv: true,
    ));

    expect(File::exists($pdfPath))->toBeTrue();

    File::delete([$pfxPath, $pdfPath]);
});

it('signs a pdf using an uploaded certificate', function () {
    [$pfxPath, $pass] = debugCertificate();

    $uploadedFile = new UploadedFile($pfxPath, 'testCertificate.pfx', null, null, true);

    $pdfPath = A1PdfSign::tempPath(true, '.pdf');
    File::put($pdfPath, A1PdfSign::signFromUpload($uploadedFile, $pass, resource('test.pdf')));

    expect(File::exists($pdfPath))->toBeTrue();

    File::delete([$pfxPath, $pdfPath]);
});

it('signs a pdf using an uploaded certificate with the PATH env', function () {
    [$pfxPath, $pass] = debugCertificate();

    $uploadedFile = new UploadedFile($pfxPath, 'testCertificate.pfx', null, null, true);

    $pdfPath = A1PdfSign::tempPath(true, '.pdf');
    File::put($pdfPath, A1PdfSign::signFromUpload(
        uploadedPfx: $uploadedFile,
        password: $pass,
        pdfPath: resource('test.pdf'),
        usePathEnv: true,
    ));

    expect(File::exists($pdfPath))->toBeTrue();
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
    File::put($pdfPath, A1PdfSign::signFromFile($pfxPath, $pass, resource('test.pdf')));

    expect(File::exists($pdfPath))->toBeTrue()
        ->and(A1PdfSign::validate($pdfPath)->isValidated)->toBeTrue();
});
