<?php

use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\File;
use LSNepomuceno\LaravelA1PdfSign\Entities\CertificateProcessed;
use LSNepomuceno\LaravelA1PdfSign\Exceptions\FileNotFoundException;
use LSNepomuceno\LaravelA1PdfSign\Exceptions\InvalidPFXException;
use LSNepomuceno\LaravelA1PdfSign\Exceptions\ProcessRunTimeException;
use LSNepomuceno\LaravelA1PdfSign\Sign\ManageCert;

test('validate certificate structure from pfx file', function () {
    $cert = new ManageCert;
    $cert->makeDebugCertificate();

    expect($cert->getCert())->toBeInstanceOf(CertificateProcessed::class);

    foreach (['original', 'openssl', 'data', 'password'] as $key) {
        expect($cert->getCert()->toArray())->toHaveKey($key);
    }

    $this->assertStringContainsStringIgnoringCase('BEGIN CERTIFICATE', $cert->getCert()->original);

    is_object($cert->getCert()->openssl)
        ? expect($cert->getCert()->openssl)->toBeInstanceOf(OpenSSLCertificate::class)
        : expect($cert->getCert()->openssl)->toBeResource();

    expect($cert->getCert()->data)->toBeArray();
    expect($cert->getCert()->data)->toHaveKey('validTo_time_t');
    //important field
    expect($cert->getCert()->password)->not->toBeNull();
});

test('validate not found pfx file exception', function () {
    $this->expectException(FileNotFoundException::class);

    $cert = new ManageCert;
    $cert->fromPfx('imaginary/path/to/file.pfx', '12345');
});

test('validate pfx file extension exception', function () {
    $this->expectException(InvalidPFXException::class);

    $cert = new ManageCert;
    $cert->fromPfx('imaginary/path/to/file.pfz', '12345');
});

test('validate encryper instance and resources', function () {
    $cert = new ManageCert;
    $cert->makeDebugCertificate();

    expect($cert->getEncrypter())->toBeInstanceOf(Encrypter::class);
    expect($cert->getEncrypter()->supported($cert->getHashKey(), $cert::CIPHER))->toBeTrue();
});

test('validate process run time exception', function () {
    $this->expectException(ProcessRunTimeException::class);

    $cert = new ManageCert;
    $cert->makeDebugCertificate(false, true);
});

test('validates if the pfx file will be deleted after being preserved', function () {
    $cert             = new ManageCert;
    [$pfxPath, $pass] = $cert->makeDebugCertificate(true);

    $cert->setPreservePfx()->fromPfx($pfxPath, $pass);

    expect(File::exists($pfxPath))->toBeTrue();
    expect(File::delete($pfxPath))->toBeTrue();
});
