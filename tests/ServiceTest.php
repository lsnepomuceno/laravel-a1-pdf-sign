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
    A1PdfSign::signFromFile($pfxPath, $pass, resource('test.pdf'))->save($pdfPath);

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

    expect($restored->original)->toContain('BEGIN CERTIFICATE');
});

it('encrypts a PEM certificate for storage and reads it back', function () {
    // The vault stores PEM either way, so the only thing that had to change is
    // what it accepts on the way in.
    [, , $bundlePath, $pass] = pemCertificate();

    $encrypted = A1PdfSign::encryptCertificate($bundlePath, $pass);

    $restored = A1PdfSign::decryptCertificate(
        $encrypted->hash,
        $encrypted->certificate,
        $encrypted->password,
    );

    expect($restored->original)->toContain('BEGIN CERTIFICATE')
        ->and($restored->commonName())->toBe('Test Certificate');
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
        public function signFromFile(string $p, string $pw, string $pdf, ?bool $env = null): \LSNepomuceno\LaravelA1PdfSign\Data\SignedPdf
        {
            return new \LSNepomuceno\LaravelA1PdfSign\Data\SignedPdf('faked');
        }

        public function signFromPem(string $pem, string $pw, string $pdf, ?string $key = null): \LSNepomuceno\LaravelA1PdfSign\Data\SignedPdf
        {
            return new \LSNepomuceno\LaravelA1PdfSign\Data\SignedPdf('faked');
        }

        public function signFromUpload(\Illuminate\Http\UploadedFile $upload, string $pw, string $pdf, ?bool $env = null): \LSNepomuceno\LaravelA1PdfSign\Data\SignedPdf
        {
            return new \LSNepomuceno\LaravelA1PdfSign\Data\SignedPdf('faked');
        }

        public function encryptCertificate(\Illuminate\Http\UploadedFile|string $path, string $pw, ?bool $env = null): EncryptedCertificate
        {
            return new EncryptedCertificate('c', 'p', 'h');
        }

        public function decryptCertificate(string $h, string $c, string $pw, bool $b64 = false, ?bool $env = null): \LSNepomuceno\LaravelA1PdfSign\Data\Certificate
        {
            return new \LSNepomuceno\LaravelA1PdfSign\Data\Certificate('pem', false, [], '');
        }

        /**
         * @return list<\LSNepomuceno\LaravelA1PdfSign\Data\SignatureField>
         */
        public function signatureFields(string $pdfPath): array
        {
            return [];
        }

        public function validate(string $pdfPath): SignatureReport
        {
            return new SignatureReport([]);
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
    expect(A1PdfSign::signFromFile('a.pfx', 'x', 'b.pdf')->contents)->toBe('faked')
        ->and(A1PdfSign::validate('b.pdf')->isSigned())->toBeFalse();
});
