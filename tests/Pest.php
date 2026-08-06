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
    return (new LSNepomuceno\LaravelA1PdfSign\Sign\ManageCert())->makeDebugCertificate(true);
}

/**
 * Absolute path of a file under tests/Resources.
 */
function resource(string $name): string
{
    return __DIR__ . '/Resources/' . $name;
}
