<?php

use LSNepomuceno\LaravelA1PdfSign\Support\ProcessRunner;

/**
 * What a consumer actually receives from Packagist.
 *
 * Everything built for testing and validation is **development tooling and
 * must not reach production**: not the fixtures, not the Docker files, not the
 * configuration of tools a consumer never runs. `.gitattributes` says so with
 * `export-ignore`, and nothing checked that it still did.
 *
 * It had already drifted. `phpstan.neon`, `pint.json`,
 * `composer-dependency-analyser.php` and `package-lock.json` were all being
 * shipped, because each was added later than the list.
 *
 * See docs/spec/quality-policy.md.
 */

/**
 * @return list<string>
 */
function distributedFiles(): array
{
    // git archive is what Packagist builds a dist from, so asking it is asking
    // the question consumers experience rather than a proxy for it.
    //
    // It reads the committed .gitattributes, not the working tree, so an
    // uncommitted change to that file shows up one commit late here and is
    // always current in CI, which checks out a commit.
    $listing = app(ProcessRunner::class)->run('git archive HEAD | tar t 2>/dev/null');

    $paths = [];

    foreach (explode("\n", $listing) as $line) {
        $path = trim($line);

        // Directory entries end in a slash and say nothing a file does not.
        if ($path !== '' && ! str_ends_with($path, '/')) {
            $paths[] = $path;
        }
    }

    return $paths;
}

it('ships the package and nothing built for testing it', function () {
    $shipped = distributedFiles();

    // Anything outside these is either a development tool or an oversight.
    $allowed = ['src/', 'config/'];
    $files = ['composer.json', 'composer.lock', 'LICENSE.md', 'README.md', 'UPGRADE.md'];

    $unexpected = [];

    foreach ($shipped as $path) {
        $expected = in_array($path, $files, true);

        foreach ($allowed as $prefix) {
            $expected = $expected || str_starts_with($path, $prefix);
        }

        if (! $expected) {
            $unexpected[] = $path;
        }
    }

    expect($unexpected)->toBe([]);
});

it('ships none of the verification tooling or its fixtures', function () {
    // The instruments, their configuration and everything they read. A
    // consuming application installs a signing library, not a test bench.
    $shipped = implode("\n", distributedFiles());

    foreach ([
        'tests/',
        'samples/',
        'docs/',
        'poc/',
        '.docker/',
        '.github/',
        '.husky/',
        'phpstan.neon',
        'pint.json',
        'composer-dependency-analyser.php',
        'package.json',
        'package-lock.json',
        'phpunit.xml',
    ] as $path) {
        expect($shipped)->not->toContain($path);
    }
});

it('still ships the things a consumer needs', function () {
    // The other half of the rule: trimming the archive must not take the
    // package with it.
    $shipped = distributedFiles();

    expect($shipped)->toContain('composer.json')
        ->toContain('config/a1-pdf-sign.php')
        ->toContain('LICENSE.md')
        ->toContain('src/LaravelA1PdfSignServiceProvider.php')
        ->toContain('src/Resources/img/sign-seal.png')
        ->toContain('src/Resources/font/Roboto-Medium.ttf');
});
