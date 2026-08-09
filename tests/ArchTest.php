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

arch('enums are string-backed, so configuration can express them as plain strings')
    ->expect('LSNepomuceno\LaravelA1PdfSign\Enums')
    ->toBeStringBackedEnums();

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
