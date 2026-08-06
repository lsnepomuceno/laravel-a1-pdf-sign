<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

it('signs a pdf through the pdf:sign command', function () {
    [$pfxPath, $pass] = debugCertificate();

    $fileName = a1TempDir(true, '.pdf');

    $this->artisan('pdf:sign', [
        'pdfPath' => resource('test.pdf'),
        'pfxPath' => $pfxPath,
        'password' => $pass,
        'fileName' => $fileName,
    ])
        ->assertSuccessful()
        ->expectsOutput('Your PDF file is being signed!')
        ->expectsOutput("Your file has been signed and is available at: \"{$fileName}\"");
});

it('reports failure from the pdf:sign command when the inputs are invalid', function () {
    $this->artisan('pdf:sign', [
        'pdfPath' => a1TempDir(true, '.pdf'),
        'pfxPath' => a1TempDir(true, '.pfx'),
        'password' => Str::random(32),
        'fileName' => a1TempDir(true, '.pdf'),
    ])
        ->assertFailed()
        ->expectsOutput('Your PDF file is being signed!')
        ->expectsOutputToContain('Could not sign your file, error occurred:');
});

it('validates a signed pdf through the pdf:validate-signature command', function () {
    [$pfxPath, $pass] = debugCertificate();

    $pdfPath = a1TempDir(true, '.pdf');
    File::put($pdfPath, signPdfFromFile($pfxPath, $pass, resource('test.pdf')));

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
