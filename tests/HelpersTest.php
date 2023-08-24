<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use LSNepomuceno\LaravelA1PdfSign\Sign\ManageCert;

test('when a file is signed by the sign pdf from file helper', function () {
    $cert             = new ManageCert;
    [$pfxPath, $pass] = $cert->makeDebugCertificate(true);

    $signed  = signPdfFromFile($pfxPath, $pass, __DIR__ . '/Resources/test.pdf');
    $pdfPath = a1TempDir(true, '.pdf');

    File::put($pdfPath, $signed);
    $fileExists = File::exists($pdfPath);

    expect($fileExists)->toBeTrue();
    File::delete([$pfxPath, $pdfPath]);
});

test('when a file is signed by the sign pdf from upload helper', function () {
    $cert             = new ManageCert;
    [$pfxPath, $pass] = $cert->makeDebugCertificate(true);

    $uploadedFile = new UploadedFile($pfxPath, 'testCertificate.pfx', null, null, true);
    $signed       = signPdfFromUpload($uploadedFile, $pass, __DIR__ . '/Resources/test.pdf');
    $pdfPath      = a1TempDir(true, '.pdf');

    File::put($pdfPath, $signed);
    $fileExists = File::exists($pdfPath);

    expect($fileExists)->toBeTrue();
    File::delete([$pfxPath, $pdfPath]);
});

test('when certificate data is encrypted', function () {
    $cert             = new ManageCert;
    [$pfxPath, $pass] = $cert->makeDebugCertificate(true);

    $encryptedData = encryptCertData($pfxPath, $pass);

    foreach (['certificate', 'password', 'hash'] as $key) {
        expect($encryptedData->toArray())->toHaveKey($key);
    }

    File::delete([$pfxPath]);
});

test('when the a1 temp dir helper creates the files correctly', function () {
    expect(File::isDirectory(a1TempDir()))->toBeTrue();

    expect(Str::endsWith(a1TempDir(true), '.pfx'))->toBeTrue();

    expect(Str::endsWith(a1TempDir(true, '.pdf'), '.pdf'))->toBeTrue();
});

test('when a signed pdf file is correctly validated by the validate pdf signature helper', function () {
    $cert             = new ManageCert;
    [$pfxPath, $pass] = $cert->makeDebugCertificate(true);

    $signed  = signPdfFromFile($pfxPath, $pass, __DIR__ . '/Resources/test.pdf');
    $pdfPath = a1TempDir(true, '.pdf');

    File::put($pdfPath, $signed);
    $fileExists = File::exists($pdfPath);

    expect($fileExists)->toBeTrue();

    $validation = validatePdfSignature($pdfPath);
    expect($validation->isValidated)->toBeTrue();

    File::delete([$pfxPath, $pdfPath]);
});
