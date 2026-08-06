<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;

it('signs a file through the signPdfFromFile helper', function () {
    [$pfxPath, $pass] = debugCertificate();

    $pdfPath = a1TempDir(true, '.pdf');
    File::put($pdfPath, signPdfFromFile($pfxPath, $pass, resource('test.pdf')));

    expect(File::exists($pdfPath))->toBeTrue();

    File::delete([$pfxPath, $pdfPath]);
});

it('signs a file through the signPdfFromFile helper using the PATH env', function () {
    [$pfxPath, $pass] = debugCertificate();

    $pdfPath = a1TempDir(true, '.pdf');
    File::put($pdfPath, signPdfFromFile(
        pfxPath: $pfxPath,
        password: $pass,
        pdfPath: resource('test.pdf'),
        usePathEnv: true,
    ));

    expect(File::exists($pdfPath))->toBeTrue();

    File::delete([$pfxPath, $pdfPath]);
});

it('signs an uploaded certificate through the signPdfFromUpload helper', function () {
    [$pfxPath, $pass] = debugCertificate();

    $uploadedFile = new UploadedFile($pfxPath, 'testCertificate.pfx', null, null, true);

    $pdfPath = a1TempDir(true, '.pdf');
    File::put($pdfPath, signPdfFromUpload($uploadedFile, $pass, resource('test.pdf')));

    expect(File::exists($pdfPath))->toBeTrue();

    File::delete([$pfxPath, $pdfPath]);
});

it('signs an uploaded certificate through the signPdfFromUpload helper using the PATH env', function () {
    [$pfxPath, $pass] = debugCertificate();

    $uploadedFile = new UploadedFile($pfxPath, 'testCertificate.pfx', null, null, true);

    $pdfPath = a1TempDir(true, '.pdf');
    File::put($pdfPath, signPdfFromUpload(
        uploadedPfx: $uploadedFile,
        password: $pass,
        pdfPath: resource('test.pdf'),
        usePathEnv: true,
    ));

    expect(File::exists($pdfPath))->toBeTrue();
});

it('encrypts certificate data', function () {
    [$pfxPath, $pass] = debugCertificate();

    expect(encryptCertData($pfxPath, $pass)->toArray())
        ->toHaveKeys(['certificate', 'password', 'hash']);
});

it('creates temporary paths with the requested extension', function () {
    expect(File::isDirectory(a1TempDir()))->toBeTrue()
        ->and(a1TempDir(true))->toEndWith('.pfx')
        ->and(a1TempDir(true, '.pdf'))->toEndWith('.pdf');
});

it('validates a signed file through the validatePdfSignature helper', function () {
    [$pfxPath, $pass] = debugCertificate();

    $pdfPath = a1TempDir(true, '.pdf');
    File::put($pdfPath, signPdfFromFile($pfxPath, $pass, resource('test.pdf')));

    expect(File::exists($pdfPath))->toBeTrue()
        ->and(validatePdfSignature($pdfPath)->isValidated)->toBeTrue();
});
