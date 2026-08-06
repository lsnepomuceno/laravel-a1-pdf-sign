<?php

use Illuminate\Support\Facades\File;
use LSNepomuceno\LaravelA1PdfSign\A1PdfSignManager;
use LSNepomuceno\LaravelA1PdfSign\Contracts\A1PdfSign as A1PdfSignContract;
use LSNepomuceno\LaravelA1PdfSign\Data\EncryptedCertificate;
use LSNepomuceno\LaravelA1PdfSign\Data\SignatureReport;
use LSNepomuceno\LaravelA1PdfSign\Facades\A1PdfSign;

it('binds the contract to the default manager as a singleton', function () {
    expect(app(A1PdfSignContract::class))->toBeInstanceOf(A1PdfSignManager::class)
        ->and(app(A1PdfSignContract::class))->toBe(app(A1PdfSignContract::class));
});

it('publishes a merged config', function () {
    expect(config('a1-pdf-sign.certificate.use_path_env'))->toBeFalse()
        ->and(config('a1-pdf-sign.seal.font.color'))->toBe('#16A085');
});

it('signs through the facade', function () {
    [$pfxPath, $pass] = debugCertificate();

    $pdfPath = A1PdfSign::tempPath(true, '.pdf');
    File::put($pdfPath, A1PdfSign::signFromFile($pfxPath, $pass, resource('test.pdf')));

    expect(File::exists($pdfPath))->toBeTrue()
        ->and(A1PdfSign::validate($pdfPath))->toBeInstanceOf(SignatureReport::class);

    File::delete([$pfxPath, $pdfPath]);
});

it('encrypts a certificate for storage', function () {
    [$pfxPath, $pass] = debugCertificate();

    expect(A1PdfSign::encryptCertificate($pfxPath, $pass))
        ->toBeInstanceOf(EncryptedCertificate::class);
});

it('round-trips an encrypted certificate', function () {
    [$pfxPath, $pass] = debugCertificate();

    $encrypted = A1PdfSign::encryptCertificate($pfxPath, $pass);

    $restored = A1PdfSign::decryptCertificate(
        $encrypted->hash,
        $encrypted->certificate,
        $encrypted->password,
    );

    expect($restored->getCert()->original)->toContain('BEGIN CERTIFICATE');
});

it('honours the configured temp path', function () {
    $custom = sys_get_temp_dir() . '/a1-config-' . uniqid();
    config()->set('a1-pdf-sign.temp_path', $custom);

    $path = A1PdfSign::tempPath(true, '.pdf');

    expect($path)->toStartWith($custom)
        ->and($path)->toEndWith('.pdf')
        ->and(File::isDirectory($custom))->toBeTrue();

    File::deleteDirectory($custom);
});

it('lets the container swap the implementation', function () {
    $fake = new class implements A1PdfSignContract {
        public function signFromFile(string $p, string $pw, string $pdf, $mode = null, ?bool $env = null): string
        {
            return 'faked';
        }

        public function signFromUpload($upload, string $pw, string $pdf, $mode = null, ?bool $env = null): string
        {
            return 'faked';
        }

        public function encryptCertificate($path, string $pw, ?bool $env = null): EncryptedCertificate
        {
            return new EncryptedCertificate('c', 'p', 'h');
        }

        public function decryptCertificate(string $h, string $c, string $pw, bool $b64 = false, ?bool $env = null): \LSNepomuceno\LaravelA1PdfSign\Sign\ManageCert
        {
            return new \LSNepomuceno\LaravelA1PdfSign\Sign\ManageCert();
        }

        public function validate(string $pdfPath): SignatureReport
        {
            return new SignatureReport(true, []);
        }

        public function newSignature(): \LSNepomuceno\LaravelA1PdfSign\Signing\PendingSignature
        {
            return app(\LSNepomuceno\LaravelA1PdfSign\Signing\PendingSignature::class);
        }

        public function tempPath(bool $tempFile = false, string $fileExt = '.pfx'): string
        {
            return '/tmp/';
        }
    };

    app()->instance(A1PdfSignContract::class, $fake);

    // The facade and every internal caller resolve the contract, so a swapped
    // implementation reaches all of them.
    expect(A1PdfSign::signFromFile('a.pfx', 'x', 'b.pdf'))->toBe('faked')
        ->and(A1PdfSign::validate('b.pdf')->isValidated)->toBeTrue();
});
