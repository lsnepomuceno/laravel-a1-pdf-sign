<?php

declare(strict_types=1);

/**
 * Architectural rules, executable.
 *
 * These turn docs/spec/invariants.md from a document into a gate: the rules it
 * describes are checked on every run, so the architecture cannot erode silently
 * after a merge.
 */
arch('no debug leftovers ship')
    ->expect(['dd', 'dump', 'var_dump', 'print_r', 'die', 'exit', 'ray'])
    ->not->toBeUsed();

/**
 * Two classes are exempt, each for one reason, and both exemptions are by class
 * rather than by loosening the rule: a third use anywhere else still fails.
 *
 * **`Data\SignatureDetails`, for sha1 only.** The Document Security Store keys
 * /VRI entries by the SHA-1 of a signature's /Contents, which the PDF
 * specification fixes. The value is an identifier defined by a format this
 * package reads, not a digest this package chose for security, and computing it
 * with anything else would simply fail to match.
 *
 * **`Signing\Encryption\StandardSecurityHandler`, for md5.** ISO 32000-1
 * §7.6.4.3 specifies MD5 as the key derivation for encryption revisions 2 to 4,
 * so a document written under one of those opens with MD5 or does not open. The
 * same class implements RC4 for the same kind of reason, to recompute a check
 * value the document already carries, and refuses RC4-encrypted *content*
 * outright (docs/decisions/0030-signing-a-document-that-is-encrypted.md).
 *
 * Using either to protect something new would be a defect. Using them to read
 * what a standard already wrote is the only way to read it at all.
 */
arch('no weak hashing or insecure randomness')
    ->expect(['md5', 'sha1', 'rand', 'srand', 'mt_rand'])
    ->not->toBeUsed()
    ->ignoring([
        'LSNepomuceno\LaravelA1PdfSign\Data\SignatureDetails',
        'LSNepomuceno\LaravelA1PdfSign\Signing\Encryption\StandardSecurityHandler',
    ]);

arch('no eval or dynamic code execution')
    ->expect(['eval', 'create_function'])
    ->not->toBeUsed();

/**
 * ddn/sapp is LGPL and this package is MIT. It is a conceptual reference for
 * the incremental writer only; a single import would make the package a
 * derivative work. See docs/spec/invariants.md.
 */
arch('no trace of SAPP')
    ->expect('ddn\Sapp')
    ->not->toBeUsed();

/**
 * Every exception extends Exception.
 *
 * Written as a walk rather than as an arch expectation, because the namespace
 * now holds an interface as well, `A1PdfSignException`, and an arch rule cannot
 * be told to skip it: `ignoring()` still reports it for not extending
 * `Exception`, which an interface never does.
 *
 * **The Stringable half of the old rule is gone because it could never fail.**
 * `Throwable` has extended `Stringable` since PHP 8, so every exception
 * satisfies it whether or not it declares `__toString()`, and three of these
 * classes do not declare one while the rule passed. PHPStan said so outright:
 * "will always evaluate to true".
 */
it('keeps every exception throwable', function () {
    $wrong = [];

    $files = glob(packageRoot() . '/src/Exceptions/*.php');

    foreach ($files === false ? [] : $files as $file) {
        $name = 'LSNepomuceno\\LaravelA1PdfSign\\Exceptions\\' . basename($file, '.php');

        if (interface_exists($name)) {
            continue;
        }

        if (! is_subclass_of($name, Exception::class)) {
            $wrong[] = $name;
        }
    }

    expect($wrong)->toBe([]);
});

arch('value objects are immutable')
    ->expect('LSNepomuceno\LaravelA1PdfSign\Data')
    ->toBeReadonly();

// BaseData is abstract, so it is exempt from both rules below.
arch('value objects are closed for extension')
    ->expect('LSNepomuceno\LaravelA1PdfSign\Data')
    ->toBeFinal()
    ->ignoring('LSNepomuceno\LaravelA1PdfSign\Data\BaseData');

arch('value objects stay on the shared base')
    ->expect('LSNepomuceno\LaravelA1PdfSign\Data')
    ->toExtend('LSNepomuceno\LaravelA1PdfSign\Data\BaseData')
    ->ignoring('LSNepomuceno\LaravelA1PdfSign\Data\BaseData');

/**
 * v2 is a clean break: no deprecation layer survives into the release.
 * See UPGRADE.md.
 */
arch('no deprecated namespace lingers')
    ->expect('LSNepomuceno\LaravelA1PdfSign\Entities')
    ->not->toBeUsed();

/**
 * The reason is the one in the name, so it reaches only the enums a
 * configuration file can name. An enum whose values are fixed by a standard and
 * are natural integers, like an ASN.1 tag, is exempted here rather than by
 * weakening the rule for every enum
 * (docs/decisions/0018-prefer-the-platforms-own-constructs.md).
 */
arch('enums are string-backed, so configuration can express them as plain strings')
    ->expect('LSNepomuceno\LaravelA1PdfSign\Enums')
    ->toBeStringBackedEnums()
    ->ignoring('LSNepomuceno\LaravelA1PdfSign\Enums\Asn1Tag');

/**
 * Str::substr() and Str::length() are multibyte-aware, so running them over a
 * PDF or a DER blob reinterprets binary as UTF-8 and returns the wrong offsets.
 * In this package a wrong offset is a corrupted signature, and the failure
 * passes the whole suite: every fixture is ASCII, and it takes a multi-byte
 * sequence in the payload to show.
 *
 * These two namespaces are where every byte-exact operation lives, so the rule
 * is "not here at all" rather than a list of permitted methods: an allowlist is
 * a rule you have to look up, and this one has to survive review from memory
 * (docs/spec/conventions.md).
 */
arch('the byte-exact namespaces do not reach for multibyte string helpers')
    ->expect('Illuminate\Support\Str')
    ->not->toBeUsedIn([
        'LSNepomuceno\LaravelA1PdfSign\Signing',
        'LSNepomuceno\LaravelA1PdfSign\Validation',
    ]);

arch('contracts are interfaces')
    ->expect('LSNepomuceno\LaravelA1PdfSign\Contracts')
    ->toBeInterfaces();

arch('facades only proxy contracts')
    ->expect('LSNepomuceno\LaravelA1PdfSign\Facades')
    ->toExtend('Illuminate\Support\Facades\Facade')
    ->toBeFinal();

/**
 * Everything that opens an external process has to go through the single
 * audited helper. See docs/decisions/0001-openssl-native-with-cli-fallback.md and docs/spec/invariants.md.
 */
arch('only the shell helper opens processes')
    ->expect(['Illuminate\Process', 'Symfony\Component\Process', 'exec', 'shell_exec', 'proc_open', 'passthru', 'system', 'popen'])
    ->toOnlyBeUsedIn('LSNepomuceno\LaravelA1PdfSign\Support\ProcessRunner');

arch('console commands stay in Commands')
    ->expect('Illuminate\Console\Command')
    ->toOnlyBeUsedIn('LSNepomuceno\LaravelA1PdfSign\Commands');

/**
 * Constants PHP defines only where the host platform provides them.
 *
 * GLOB_BRACE is a GNU extension and is undefined on musl, so a call carrying it
 * is a fatal error on php:8.4-alpine while passing everywhere CI runs. That is
 * the shape of the failure worth guarding: the suite was green on Ubuntu for a
 * whole release while `TrustStore::fromDirectory()` could not be called at all
 * in one of the images this package most often ships in.
 *
 * A Pest arch rule cannot see constants, so this reads the sources.
 */
it('uses no constant the host platform may not define', function () {
    $optional = ['GLOB_BRACE'];
    $found = [];

    /** @var SplFileInfo $file */
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(packageRoot() . '/src')) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        // Tokenised rather than grepped, so the comment above the fix can name
        // the constant it is warning about without tripping the gate.
        foreach (token_get_all((string) file_get_contents($file->getPathname())) as $token) {
            if (is_array($token) && $token[0] === T_STRING && in_array($token[1], $optional, true)) {
                $found[] = str_replace(packageRoot() . '/', '', $file->getPathname()) . ": {$token[1]}";
            }
        }
    }

    expect($found)->toBe([]);
});

/**
 * veraPDF is a measuring instrument, not a dependency.
 *
 * So are qpdf, poppler, Ghostscript and pyHanko. Every one of them is installed for
 * development and CI, to establish verdicts the suite cannot establish for
 * itself, and **none of them may reach production**
 * (docs/decisions/0025-what-signing-does-to-pdf-a.md).
 *
 * **Nothing in src/ may reach for it.** A package that shells out to a JVM to
 * answer a question at runtime would be a different package, and the consuming
 * application would inherit an installation requirement nobody wrote down. The
 * same applies to poppler's pdfsig, which has verified this package's output
 * since 2.0 and has never been called by it.
 */
it('keeps the verification tools out of the package', function () {
    $tools = ['verapdf', 'veraPDF', 'pdfsig', 'pdftoppm', 'qpdf', 'ghostscript', 'pyhanko'];
    $found = [];

    /** @var SplFileInfo $file */
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(packageRoot() . '/src')) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        // Only string literals. Naming a tool in a docblock is expected and
        // happens: what must not exist is a code path that invokes one, and
        // matching the raw text would flag the comment explaining that.
        foreach (token_get_all((string) file_get_contents($file->getPathname())) as $token) {
            if (! is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING) {
                continue;
            }

            foreach ($tools as $tool) {
                if (stripos($token[1], $tool) !== false) {
                    $found[] = str_replace(packageRoot() . '/', '', $file->getPathname()) . ": {$tool}";
                }
            }
        }
    }

    expect($found)->toBe([]);
});

/*
|--------------------------------------------------------------------------
| Docblocks
|--------------------------------------------------------------------------
|
| Checked mechanically, because prose rots quietly. Both rules below describe
| defects that actually shipped rather than defects worth worrying about, and
| neither is a style preference: a docblock that documents nothing is a comment
| nobody reads, and a docblock that documents the wrong thing is worse than
| none, because it is believed (docs/spec/conventions.md).
|
| The first of them caught this very file, five minutes after it was written.
|
*/

/**
 * Every PHP file under a directory, as relative path to contents.
 *
 * @return Generator<string, string>
 */
function phpFilesUnder(string $directory): Generator
{
    /** @var SplFileInfo $file */
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory)) as $file) {
        if ($file->getExtension() === 'php') {
            yield str_replace(packageRoot() . '/', '', $file->getPathname()) => (string) file_get_contents($file->getPathname());
        }
    }
}

it('never leaves a docblock documenting another docblock', function (string $directory) {
    // What this catches: inserting a method between a docblock and the method
    // it described, which leaves the first one attached to the newcomer and the
    // original method undocumented. Both blocks look fine in isolation, the
    // diff looks like an addition, and every tool that reads docblocks now
    // reports the wrong thing about two methods.
    //
    // Found four times across src/ and tests/ on the day this was written, one
    // of them describing `latest()` while sitting above `timestamps()`.
    $found = [];

    foreach (phpFilesUnder(packageRoot() . '/' . $directory) as $path => $contents) {
        $lines = explode("\n", $contents);

        foreach ($lines as $number => $line) {
            if (trim($line) === '*/' && str_starts_with(trim($lines[$number + 1] ?? ''), '/**')) {
                $found[] = "{$path}:" . ($number + 2);
            }
        }
    }

    expect($found)->toBe([]);
})->with(['src', 'tests']);

it('documents parameters that exist', function () {
    // A @param surviving a rename or a removal is the other half of the same
    // problem: the signature moved and the prose did not.
    $found = [];

    foreach (phpFilesUnder(packageRoot() . '/src') as $path => $contents) {
        preg_match_all(
            '#/\*\*(.*?)\*/\s*(?:\#\[[^\]]*\]\s*)*(?:(?:public|private|protected|final|static|abstract)\s+)*function\s+(\w+)\s*\((.*?)\)\s*[:{]#s',
            $contents,
            $matches,
            PREG_SET_ORDER,
        );

        foreach ($matches as [, $doc, $method, $parameters]) {
            preg_match_all('/@param\s+\S+\s+\$(\w+)/', $doc, $documented);

            foreach ($documented[1] as $name) {
                if (preg_match('/\$' . $name . '\b/', $parameters) !== 1) {
                    $found[] = "{$path}: {$method}() documents \${$name}";
                }
            }
        }
    }

    expect($found)->toBe([]);
});

/**
 * The front door describes the package.
 *
 * `icpBrasil()` and `extendArchive()` were both public for a release before the
 * README mentioned either, which is the same failure as a stale docblock at a
 * larger scale: the thing that tells people what the package does had stopped
 * being true. A method a consumer is expected to call is a method the first
 * page should name (CONTRIBUTING.md).
 *
 * The check is deliberately shallow. It asks whether the name appears, not
 * whether what is written about it is any good, because only the second is
 * worth a human's time and only the first can be checked at all.
 */
it('names every entry point on the front page', function () {
    $readme = (string) file_get_contents(packageRoot() . '/README.md');
    $missing = [];

    foreach (new ReflectionClass(LSNepomuceno\LaravelA1PdfSign\Contracts\A1PdfSign::class)->getMethods() as $method) {
        // tempPath() is infrastructure a consumer never calls on purpose, and
        // newSignature() appears as the builder in every example rather than by
        // name.
        if (in_array($method->getName(), ['tempPath', 'newSignature'], true)) {
            continue;
        }

        if (! str_contains($readme, $method->getName())) {
            $missing[] = $method->getName();
        }
    }

    expect($missing)->toBe([]);
});

/**
 * Every file declares strict types.
 *
 * `docs/spec/conventions.md` makes it mandatory, and `pint.json` writes it, so
 * this exists for the case Pint cannot reach: a file added outside the
 * formatter's path, or the rule being switched off again. It was off on
 * purpose until 2026-08-12, `"declare_strict_types": false`, and none of the
 * 169 files carried the declaration.
 *
 * The arch expectation covers `src/`. It cannot cover `tests/` or `config/`,
 * because arch expectations work on classes and those files declare none:
 * `config/a1-pdf-sign.php` returns an array and the test files are closures.
 * Hence the walk below as well.
 */
arch('src declares strict types')
    ->expect('LSNepomuceno\LaravelA1PdfSign')
    ->toUseStrictTypes();

it('declares strict types in every file, including the ones with no class in them', function () {
    $missing = [];

    foreach (['/src', '/tests', '/config'] as $directory) {
        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(packageRoot() . $directory)) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $contents = (string) file_get_contents($file->getPathname());

            if (! str_contains($contents, 'declare(strict_types=1);')) {
                $missing[] = str_replace(packageRoot() . '/', '', $file->getPathname());
            }
        }
    }

    sort($missing);

    // poc/ is out of scope, as it is for Pint and PHPStan: throwaway spikes,
    // not production code.
    expect($missing)->toBe([]);
});
