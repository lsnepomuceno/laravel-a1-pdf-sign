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

arch('entities stay on the shared base')
    ->expect('LSNepomuceno\LaravelA1PdfSign\Entities')
    ->toExtend('LSNepomuceno\LaravelA1PdfSign\Entities\BaseEntity')
    ->ignoring('LSNepomuceno\LaravelA1PdfSign\Entities\BaseEntity');

arch('console commands stay in Commands')
    ->expect('Illuminate\Console\Command')
    ->toOnlyBeUsedIn('LSNepomuceno\LaravelA1PdfSign\Commands');
