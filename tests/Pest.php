<?php

declare(strict_types=1);

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
| The optional integrations, grouped by the SDK each one needs. CI runs the
| suite once more with both SDKs removed and these groups excluded, which is
| how "the package works without them" stays true rather than assumed.
|
| `project` goes with them: its walks load every class under src/ and tests/,
| and the ones naming an SDK cannot load without it. Its rules are about the
| source, which the main jobs check with everything installed.
*/
uses()->group('mcp')->in('Mcp');
uses()->group('ai')->in('Ai');
uses()->group('project')->in('Project');

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
    [$pfx, $password] = LSNepomuceno\Signet\Testing\DebugCertificate::make();

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
 *                                                           the key's password, empty when
 *                                                           it is unencrypted.
 */
function pemCertificate(bool $encryptKey = true): array
{
    [$certificate, $privateKey, $password] = LSNepomuceno\Signet\Testing\DebugCertificate::makePem($encryptKey);

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
function testCertificate(): LSNepomuceno\Signet\Data\Certificate
{
    [$pfx, $password] = LSNepomuceno\Signet\Testing\DebugCertificate::make();

    return app(LSNepomuceno\Signet\Certificates\NativeCertificateReader::class)
        ->read($pfx, $password);
}

/**
 * Signs tests/Resources/test.pdf for real and stores it on a disk, opening
 * that disk to agents.
 *
 * Shared by the MCP and AI SDK tests, which both need a genuinely signed
 * document to read: a validation tool proven only against unsigned files
 * proves very little.
 *
 * @return string The certificate's common name, which the report should name.
 */
function signedOnDisk(string $disk, string $path): string
{
    [$pfxPath, $password] = debugCertificate();

    Illuminate\Support\Facades\Storage::disk($disk)->put(
        $path,
        LSNepomuceno\LaravelA1PdfSign\Facades\A1PdfSign::signFromFile($pfxPath, $password, resource('test.pdf'))->contents,
    );

    config()->set('a1-pdf-sign.agents.disks', [$disk]);

    return 'Test Certificate';
}

/**
 * Absolute path of a file under tests/Resources.
 */
function resource(string $name): string
{
    return __DIR__ . '/Resources/' . $name;
}

/**
 * The root of the package.
 *
 * Here rather than in the file that first needed it, because several test files
 * walk the tree and a helper defined inside one is invisible to the others
 * under --parallel, which fails as `Call to undefined function`.
 */
function packageRoot(): string
{
    return dirname(__DIR__);
}
