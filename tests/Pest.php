<?php

use LSNepomuceno\LaravelA1PdfSign\Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| Every test in this directory runs against Testbench's application harness,
| with the package's service provider registered. See tests/TestCase.php.
|
*/

uses(TestCase::class)->in(__DIR__);

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

/**
 * Generates a throwaway PFX certificate and returns its path and password.
 *
 * @return array{0: string, 1: string}
 */
function debugCertificate(): array
{
    [$pfx, $password] = LSNepomuceno\LaravelA1PdfSign\Testing\DebugCertificate::make();

    $path = LSNepomuceno\LaravelA1PdfSign\Facades\A1PdfSign::tempPath(true, '.pfx');
    file_put_contents($path, $pfx);

    return [$path, $password];
}

/**
 * Generates a throwaway PEM certificate on disk, in both shapes the entry point
 * accepts: certificate and key as separate files, and the two combined.
 *
 * @return array{0: string, 1: string, 2: string, 3: string} Certificate path, private key
 *                                                           path, combined bundle path, and
 *                                                           the key's password — empty when
 *                                                           it is unencrypted.
 */
function pemCertificate(bool $encryptKey = true): array
{
    [$certificate, $privateKey, $password] = LSNepomuceno\LaravelA1PdfSign\Testing\DebugCertificate::makePem($encryptKey);

    $certificatePath = LSNepomuceno\LaravelA1PdfSign\Facades\A1PdfSign::tempPath(true, '.pem');
    $privateKeyPath = LSNepomuceno\LaravelA1PdfSign\Facades\A1PdfSign::tempPath(true, '.key');
    $bundlePath = LSNepomuceno\LaravelA1PdfSign\Facades\A1PdfSign::tempPath(true, '.pem');

    file_put_contents($certificatePath, $certificate);
    file_put_contents($privateKeyPath, $privateKey);
    file_put_contents($bundlePath, $certificate . $privateKey);

    return [$certificatePath, $privateKeyPath, $bundlePath, $password];
}

/**
 * Reads a throwaway certificate straight into the object the signer expects.
 *
 * Defined here rather than in a test file: helpers that live in one test file
 * are invisible to the others once the suite runs in parallel.
 */
function testCertificate(): LSNepomuceno\LaravelA1PdfSign\Data\Certificate
{
    [$pfx, $password] = LSNepomuceno\LaravelA1PdfSign\Testing\DebugCertificate::make();

    return app(LSNepomuceno\LaravelA1PdfSign\Certificates\NativeCertificateReader::class)
        ->read($pfx, $password);
}

/**
 * Absolute path of a file under tests/Resources.
 */
function resource(string $name): string
{
    return __DIR__ . '/Resources/' . $name;
}
