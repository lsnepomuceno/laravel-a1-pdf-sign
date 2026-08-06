<?php

use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\File;
use LSNepomuceno\LaravelA1PdfSign\Data\Certificate;
use LSNepomuceno\LaravelA1PdfSign\Exceptions\FileNotFoundException;
use LSNepomuceno\LaravelA1PdfSign\Exceptions\InvalidPFXException;
use LSNepomuceno\LaravelA1PdfSign\Exceptions\ProcessRunTimeException;
use LSNepomuceno\LaravelA1PdfSign\Sign\ManageCert;

it('exposes the parsed structure of a PFX certificate', function () {
    $cert = new ManageCert();
    $cert->makeDebugCertificate();

    expect($cert->getCert())->toBeInstanceOf(Certificate::class)
        ->and($cert->getCert()->toArray())->toHaveKeys(['original', 'openssl', 'data', 'password'])
        ->and($cert->getCert()->original)->toContain('BEGIN CERTIFICATE')
        ->and($cert->getCert()->openssl)->toBeInstanceOf(OpenSSLCertificate::class)
        ->and($cert->getCert()->data)->toBeArray()
        // validTo_time_t drives the seal's expiry line; losing it breaks SealImage.
        ->toHaveKey('validTo_time_t')
        ->and($cert->getCert()->password)->not->toBeNull();
});

it('rejects a PFX path that does not exist', function () {
    (new ManageCert())->fromPfx('imaginary/path/to/file.pfx', '12345');
})->throws(FileNotFoundException::class);

it('rejects a file that is not a PFX', function () {
    (new ManageCert())->fromPfx('imaginary/path/to/file.pfz', '12345');
})->throws(InvalidPFXException::class);

it('builds a supported encrypter', function () {
    $cert = new ManageCert();
    $cert->makeDebugCertificate();

    expect($cert->getEncrypter())->toBeInstanceOf(Encrypter::class)
        ->and($cert->getEncrypter()->supported($cert->getHashKey(), $cert::CIPHER))->toBeTrue();
});

it('surfaces openssl failures as ProcessRunTimeException', function () {
    (new ManageCert())->makeDebugCertificate(false, true);
})->throws(ProcessRunTimeException::class);

it('keeps the PFX on disk when preservation is requested', function () {
    $cert = new ManageCert();
    [$pfxPath, $pass] = $cert->makeDebugCertificate(true);

    $cert->setPreservePfx()->fromPfx($pfxPath, $pass);

    expect(File::exists($pfxPath))->toBeTrue();

    File::delete($pfxPath);
});
