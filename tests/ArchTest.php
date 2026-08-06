<?php

/**
 * Architectural rules, executable.
 *
 * These turn ARCHITECTURE-V2.md from a document into a gate: the constraints
 * it describes are checked on every run, so the architecture cannot erode
 * silently after a merge. The set grows as the v2 PRs land.
 */
arch('no debug leftovers ship')
    ->expect(['dd', 'dump', 'var_dump', 'print_r', 'die', 'exit', 'ray'])
    ->not->toBeUsed();

arch('no weak hashing or insecure randomness')
    ->expect(['md5', 'sha1', 'rand', 'srand', 'mt_rand'])
    ->not->toBeUsed();

arch('no eval or dynamic code execution')
    ->expect(['eval', 'create_function'])
    ->not->toBeUsed();

/**
 * ddn/sapp is LGPL and this package is MIT. It is a conceptual reference for
 * the incremental writer only; a single import would make the package a
 * derivative work. See ARCHITECTURE-V2.md §3h.
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
 * See ARCHITECTURE-V2.md §4.
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
 * audited helper. See ARCHITECTURE-V2.md §3a and §6.2.
 */
arch('only the shell helper opens processes')
    ->expect(['Symfony\Component\Process', 'exec', 'shell_exec', 'proc_open', 'passthru', 'system', 'popen'])
    ->toOnlyBeUsedIn('LSNepomuceno\LaravelA1PdfSign\Support\ProcessRunner');

arch('console commands stay in Commands')
    ->expect('Illuminate\Console\Command')
    ->toOnlyBeUsedIn('LSNepomuceno\LaravelA1PdfSign\Commands');
