<?php

use Illuminate\Support\Facades\File;
use LSNepomuceno\LaravelA1PdfSign\Certificates\CertificateParser;
use LSNepomuceno\LaravelA1PdfSign\Certificates\CertificateVault;
use LSNepomuceno\LaravelA1PdfSign\Certificates\NativeCertificateReader;
use LSNepomuceno\LaravelA1PdfSign\Certificates\OpenSslCliCertificateReader;
use LSNepomuceno\LaravelA1PdfSign\Certificates\ReaderFactory;
use LSNepomuceno\LaravelA1PdfSign\Data\Certificate;
use LSNepomuceno\LaravelA1PdfSign\Exceptions\InvalidCertificateContentException;
use LSNepomuceno\LaravelA1PdfSign\Support\TemporaryFile;
use LSNepomuceno\LaravelA1PdfSign\Testing\DebugCertificate;

it('reads a PKCS#12 bundle natively, without touching disk or a shell', function () {
    [$pfx, $password] = DebugCertificate::make();

    $certificate = app(NativeCertificateReader::class)->read($pfx, $password);

    expect($certificate)->toBeInstanceOf(Certificate::class)
        ->and($certificate->original)->toContain('BEGIN CERTIFICATE')
        ->and($certificate->original)->toContain('PRIVATE KEY')
        ->and($certificate->commonName())->toBe('Test Certificate')
        ->and($certificate->isExpired())->toBeFalse();
});

it('rejects a wrong password with a reason', function () {
    [$pfx] = DebugCertificate::make();

    app(NativeCertificateReader::class)->read($pfx, 'not-the-password');
})->throws(InvalidCertificateContentException::class);

it('produces the same PEM through the CLI reader', function () {
    [$pfx, $password] = DebugCertificate::make();

    $native = app(NativeCertificateReader::class)->read($pfx, $password);
    $cli = app(ReaderFactory::class)->make(legacy: true)->read($pfx, $password);

    expect($cli)->toBeInstanceOf(Certificate::class)
        // Both drivers must yield an interchangeable bundle, otherwise the
        // legacy fallback would silently change what gets signed.
        ->and($cli->data['subject'])->toBe($native->data['subject'])
        ->and($cli->original)->toContain('BEGIN CERTIFICATE');
});

it('selects the reader from the legacy flag', function () {
    $factory = app(ReaderFactory::class);

    expect($factory->make(legacy: false))->toBeInstanceOf(NativeCertificateReader::class)
        ->and($factory->make(legacy: true))->toBeInstanceOf(OpenSslCliCertificateReader::class);
});

it('seals and opens a certificate through the vault', function () {
    [$pfx, $password] = DebugCertificate::make();

    $certificate = app(NativeCertificateReader::class)->read($pfx, $password);

    $vault = CertificateVault::create();
    $sealed = $vault->seal($certificate, $password);

    $opened = CertificateVault::withKey($sealed->hash)
        ->open(app(CertificateParser::class), $sealed->certificate, $sealed->password);

    expect($opened->original)->toBe($certificate->original)
        ->and($opened->password)->toBe($password);
});

it('deletes a temporary file even when the callback throws', function () {
    $path = null;

    try {
        TemporaryFile::with(sys_get_temp_dir(), '.tmp', 'x', function (TemporaryFile $file) use (&$path) {
            $path = $file->path;

            expect(File::exists($path))->toBeTrue();

            throw new RuntimeException('boom');
        });
    } catch (RuntimeException) {
        // expected
    }

    expect(File::exists($path))->toBeFalse();
});

it('generates a debug certificate without shelling out', function () {
    [$pfx, $password] = DebugCertificate::make();

    expect($pfx)->not->toBeEmpty()
        ->and($password)->toBe(DebugCertificate::PASSWORD)
        ->and(app(NativeCertificateReader::class)->read($pfx, $password))
        ->toBeInstanceOf(Certificate::class);
});
