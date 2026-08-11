<?php

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
 * SignatureDetails is exempt, and only for sha1. The Document Security Store
 * keys /VRI entries by the SHA-1 of a signature's /Contents, which the PDF
 * specification fixes: the value is an identifier defined by a format this
 * package reads, not a digest this package chose for security. Computing it
 * with anything else would simply fail to match.
 *
 * The exemption is by class rather than by loosening the rule, so a second use
 * of sha1 anywhere else still fails.
 */
arch('no weak hashing or insecure randomness')
    ->expect(['md5', 'sha1', 'rand', 'srand', 'mt_rand'])
    ->not->toBeUsed()
    ->ignoring('LSNepomuceno\LaravelA1PdfSign\Data\SignatureDetails');

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

arch('exceptions are throwable and printable')
    ->expect('LSNepomuceno\LaravelA1PdfSign\Exceptions')
    ->toExtend(Exception::class)
    ->toImplement(Stringable::class);

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
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__) . '/src')) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        // Tokenised rather than grepped, so the comment above the fix can name
        // the constant it is warning about without tripping the gate.
        foreach (token_get_all((string) file_get_contents($file->getPathname())) as $token) {
            if (is_array($token) && $token[0] === T_STRING && in_array($token[1], $optional, true)) {
                $found[] = str_replace(dirname(__DIR__) . '/', '', $file->getPathname()) . ": {$token[1]}";
            }
        }
    }

    expect($found)->toBe([]);
});

/**
 * veraPDF is a measuring instrument, not a dependency.
 *
 * It is Java, it is installed only by the `pdfa` compose service and by the CI
 * job of the same name, and it exists to establish PDF/A verdicts the suite
 * cannot establish for itself
 * (docs/decisions/0025-what-signing-does-to-pdf-a.md).
 *
 * **Nothing in src/ may reach for it.** A package that shells out to a JVM to
 * answer a question at runtime would be a different package, and the consuming
 * application would inherit an installation requirement nobody wrote down. The
 * same applies to poppler's pdfsig, which has verified this package's output
 * since 2.0 and has never been called by it.
 */
it('keeps the verification tools out of the package', function () {
    $tools = ['verapdf', 'veraPDF', 'pdfsig', 'pdftoppm', 'ghostscript'];
    $found = [];

    /** @var SplFileInfo $file */
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__) . '/src')) as $file) {
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
                    $found[] = str_replace(dirname(__DIR__) . '/', '', $file->getPathname()) . ": {$tool}";
                }
            }
        }
    }

    expect($found)->toBe([]);
});
