<?php

declare(strict_types=1);

use ShipMonk\ComposerDependencyAnalyser\Config\Configuration;
use ShipMonk\ComposerDependencyAnalyser\Config\ErrorType;

return (new Configuration())
    ->addPathToScan(__DIR__ . '/src', isDev: false)
    ->addPathToScan(__DIR__ . '/tests', isDev: true)

    /*
     * tests/ reaches PHPUnit directly, and Pest brings it transitively, so
     * every suite that can run these tests already has it.
     *
     * src/Testing is no longer among the paths: the fake delegates its
     * assertions to signet-pdf's recorder rather than calling Assert itself,
     * so the reference that needed this exception is gone.
     */
    ->ignoreErrorsOnPackageAndPaths(
        'phpunit/phpunit',
        [__DIR__ . '/tests'],
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
     * The two optional integrations. Each SDK is a dev requirement, so the
     * suite and PHPStan can see it, and a suggestion for consumers, and each
     * is reached from exactly one directory of src/. tests/Project/ArchTest.php
     * holds the other half: nothing outside that directory may name the SDK,
     * which is what keeps the package loadable when the SDK is absent
     * (docs/decisions/0040-agents-read-through-mcp-and-sign-through-the-ai-sdk.md).
     */
    ->ignoreErrorsOnPackageAndPaths('laravel/ai', [__DIR__ . '/src/Ai'], [ErrorType::DEV_DEPENDENCY_IN_PROD])
    ->ignoreErrorsOnPackageAndPaths('laravel/mcp', [__DIR__ . '/src/Mcp'], [ErrorType::DEV_DEPENDENCY_IN_PROD])

    /*
     * Dev-only tooling reached through Pest's global functions and Testbench's
     * base class, neither of which is a direct require.
     */
    ->ignoreErrorsOnPackages(
        ['orchestra/testbench-core', 'pestphp/pest-plugin-arch'],
        [ErrorType::SHADOW_DEPENDENCY],
    );
