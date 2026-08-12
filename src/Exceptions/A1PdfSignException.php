<?php

declare(strict_types=1);

namespace LSNepomuceno\LaravelA1PdfSign\Exceptions;

use Throwable;

/**
 * Every failure this package raises.
 *
 * The classes are granular on purpose, one per failure mode
 * (docs/decisions/0008-exceptions-name-the-real-fault.md), and that left a
 * consumer with no way to catch them as a group: the choices were naming
 * sixteen classes or catching \Exception and swallowing everything the
 * framework throws with them.
 *
 * In a Laravel application that matters in bootstrap/app.php, where reporting
 * and rendering are registered by class:
 *
 * ```php
 * ->withExceptions(function (Exceptions $exceptions) {
 *     $exceptions->report(function (A1PdfSignException $e) { … });
 * })
 * ```
 *
 * An interface rather than a base class: several of these may want to extend a
 * framework or SPL type later, and a base class forecloses that. Adding it to
 * existing classes is backward compatible, since every current catch keeps
 * matching.
 */
interface A1PdfSignException extends Throwable {}
