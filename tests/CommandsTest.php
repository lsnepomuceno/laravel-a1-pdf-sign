<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use LSNepomuceno\LaravelA1PdfSign\Sign\ManageCert;

test('when the signature command is successfully completed', function () {
    $cert             = new ManageCert;
    [$pfxPath, $pass] = $cert->makeDebugCertificate(true);
    $pdfPath          = __DIR__ . '/Resources/test.pdf';
    $fileName         = a1TempDir(true, '.pdf');
    $parameters       = [
        'pdfPath'  => $pdfPath,
        'pfxPath'  => $pfxPath,
        'password' => $pass,
        'fileName' => $fileName,
    ];

    $this->artisan('pdf:sign', $parameters)
        ->assertSuccessful()
        ->expectsOutput('Your PDF file is being signed!')
        ->expectsOutput("Your file has been signed and is available at: \"{$fileName}\"");

    File::delete([$pfxPath, $fileName]);
});
test('when the signature command does not finish successfully', function () {
    $parameters = [
        'pdfPath'  => a1TempDir(true, '.pdf'),
        'pfxPath'  => a1TempDir(true, '.pfx'),
        'password' => Str::random(32),
        'fileName' => a1TempDir(true, '.pdf'),
    ];

    $this->artisan('pdf:sign', $parameters)
//             ->assertFailed()
        ->expectsOutput('Your PDF file is being signed!')
        ->expectsOutputToContain('Could not sign your file, error occurred:');
});
test('when a signed pdf is successfully validated', function () {
    $cert             = new ManageCert;
    [$pfxPath, $pass] = $cert->makeDebugCertificate(true);

    $signed  = signPdfFromFile($pfxPath, $pass, __DIR__ . '/Resources/test.pdf');
    $pdfPath = a1TempDir(true, '.pdf');

    File::put($pdfPath, $signed);
    $fileExists = File::exists($pdfPath);

    expect($fileExists)->toBeTrue();

    $parameters = [
        'pdfPath' => $pdfPath,
    ];

    $this->artisan('pdf:validate-signature', $parameters)
        ->assertSuccessful()
        ->expectsOutput('Your PDF document is being validated.')
        ->expectsOutput('Your PDF document is VALID');

    File::delete([$pfxPath, $pdfPath]);
});
test('when an unsigned document throws an error when running a validation command', function () {
    $pdfPath    = __DIR__ . '/Resources/test.pdf';
    $parameters = [
        'pdfPath' => $pdfPath,
    ];

    $this->artisan('pdf:validate-signature', $parameters)
        ->assertFailed()
        ->expectsOutput('Your PDF document is being validated.')
        ->expectsOutputToContain('Unable to validate your file signature, an error occurred:');
});
