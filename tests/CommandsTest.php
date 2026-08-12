<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use LSNepomuceno\LaravelA1PdfSign\Facades\A1PdfSign;

it('signs a pdf through the pdf:sign command', function () {
    [$pfxPath, $pass] = debugCertificate();

    $fileName = A1PdfSign::tempPath(true, '.pdf');

    $this->artisan('pdf:sign', [
        'pdfPath' => resource('test.pdf'),
        'certificatePath' => $pfxPath,
        'password' => $pass,
        'fileName' => $fileName,
    ])
        ->assertSuccessful()
        ->expectsOutput('Your PDF file is being signed!')
        ->expectsOutput("Your file has been signed and is available at: \"{$fileName}\"");
});

it('reports failure from the pdf:sign command when the inputs are invalid', function () {
    $this->artisan('pdf:sign', [
        'pdfPath' => A1PdfSign::tempPath(true, '.pdf'),
        'certificatePath' => A1PdfSign::tempPath(true, '.pfx'),
        'password' => Str::random(32),
        'fileName' => A1PdfSign::tempPath(true, '.pdf'),
    ])
        ->assertFailed()
        ->expectsOutput('Your PDF file is being signed!')
        ->expectsOutputToContain('Could not sign your file, error occurred:');
});

it('signs with a PEM certificate through the pdf:sign command', function () {
    // No flag says "this is PEM": the command reads the encoding from the
    // bytes, because PEM ships under half a dozen extensions.
    [, , $bundlePath, $pass] = pemCertificate();

    $fileName = A1PdfSign::tempPath(true, '.pdf');

    $this->artisan('pdf:sign', [
        'pdfPath' => resource('test.pdf'),
        'certificatePath' => $bundlePath,
        'password' => $pass,
        'fileName' => $fileName,
    ])->assertSuccessful();

    expect(A1PdfSign::validate($fileName)->isValid())->toBeTrue();
});

it('signs with a PEM key given through --key', function () {
    [$certificatePath, $privateKeyPath, , $pass] = pemCertificate();

    $fileName = A1PdfSign::tempPath(true, '.pdf');

    $this->artisan('pdf:sign', [
        'pdfPath' => resource('test.pdf'),
        'certificatePath' => $certificatePath,
        'password' => $pass,
        'fileName' => $fileName,
        '--key' => $privateKeyPath,
    ])->assertSuccessful();

    expect(A1PdfSign::validate($fileName)->isValid())->toBeTrue();
});

it('rejects --key alongside a PKCS#12 bundle', function () {
    [$pfxPath, $pass] = debugCertificate();

    $this->artisan('pdf:sign', [
        'pdfPath' => resource('test.pdf'),
        'certificatePath' => $pfxPath,
        'password' => $pass,
        'fileName' => A1PdfSign::tempPath(true, '.pdf'),
        '--key' => $pfxPath,
    ])
        ->assertFailed()
        ->expectsOutputToContain('a PKCS#12 bundle already carries its key');
});

it('validates a signed pdf through the pdf:validate-signature command', function () {
    [$pfxPath, $pass] = debugCertificate();

    $pdfPath = A1PdfSign::tempPath(true, '.pdf');
    A1PdfSign::signFromFile($pfxPath, $pass, resource('test.pdf'))->save($pdfPath);

    expect(File::exists($pdfPath))->toBeTrue();

    $this->artisan('pdf:validate-signature', ['pdfPath' => $pdfPath])
        ->assertSuccessful()
        ->expectsOutput('Your PDF document is being validated.')
        ->expectsOutput('Your PDF document is VALID');
});

it('fails validation for an unsigned document', function () {
    $this->artisan('pdf:validate-signature', ['pdfPath' => resource('test.pdf')])
        ->assertFailed()
        ->expectsOutput('Your PDF document is being validated.')
        ->expectsOutputToContain('Unable to validate your file signature, an error occurred:');
});
