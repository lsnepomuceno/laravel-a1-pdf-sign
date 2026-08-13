<?php

declare(strict_types=1);

use ShipMonk\ComposerDependencyAnalyser\Config\Configuration;
use ShipMonk\ComposerDependencyAnalyser\Config\ErrorType;

return (new Configuration())
    ->addPathToScan(__DIR__ . '/src', isDev: false)
    ->addPathToScan(__DIR__ . '/tests', isDev: true)
    ->addPathToExclude(__DIR__ . '/poc')

    /*
     * Extensions are used through the libraries that wrap them: gd by
     * Intervention, fileinfo by UploadedFile, so no direct symbol reference
     * exists to detect. They stay declared because a host missing them fails at
     * runtime.
     *
     * ext-mbstring is no longer among them. It used to be here for the same
     * reason, Laravel's string handling, and Signing\Encryption\ObjectCipher
     * now calls mb_convert_encoding() directly to write a text string as
     * UTF-16BE, so the reference is real and the ignore was reported as never
     * applied. Which is the analyser doing its job in the other direction.
     */
    ->ignoreErrorsOnExtensions(
        ['ext-fileinfo', 'ext-gd'],
        [ErrorType::UNUSED_DEPENDENCY],
    )

    /*
     * src/Testing/A1PdfSignFake.php calls PHPUnit\Framework\Assert, which is
     * how a first-party fake reports a failed expectation. Laravel does the
     * same in Illuminate\Support\Testing\Fakes without requiring PHPUnit
     * either: the class is only ever reached from a test suite, where the
     * assertion library is present by definition.
     *
     * It stays out of `require` deliberately. Shipping a test framework to
     * production to support a testing helper would be a worse trade than this
     * exception.
     *
     * tests/ is covered for the same reason one level down: Pest brings PHPUnit
     * transitively, so every suite that can run these tests already has it.
     */
    ->ignoreErrorsOnPackageAndPaths(
        'phpunit/phpunit',
        [__DIR__ . '/src/Testing', __DIR__ . '/tests'],
        [ErrorType::SHADOW_DEPENDENCY],
    )

    /*
     * The suite installs laravel/framework, which provides every Illuminate
     * namespace, so the analyser attributes those symbols there rather than to
     * the split packages this library actually requires.
     */
    ->ignoreErrorsOnPackages(
        ['illuminate/console', 'illuminate/encryption', 'illuminate/http', 'illuminate/process', 'illuminate/support'],
        [ErrorType::UNUSED_DEPENDENCY],
    )
    ->ignoreErrorsOnPackage('laravel/framework', [ErrorType::SHADOW_DEPENDENCY])

    /*
     * Dev-only tooling reached through Pest's global functions and Testbench's
     * base class, neither of which is a direct require.
     */
    ->ignoreErrorsOnPackages(
        ['orchestra/testbench-core', 'pestphp/pest-plugin-arch'],
        [ErrorType::SHADOW_DEPENDENCY],
    );
