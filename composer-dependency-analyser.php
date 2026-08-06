<?php

use ShipMonk\ComposerDependencyAnalyser\Config\Configuration;
use ShipMonk\ComposerDependencyAnalyser\Config\ErrorType;

return (new Configuration())
    ->addPathToScan(__DIR__ . '/src', isDev: false)
    ->addPathToScan(__DIR__ . '/tests', isDev: true)
    ->addPathToExclude(__DIR__ . '/poc')

    /*
     * Extensions are used through the libraries that wrap them — gd by
     * Intervention, fileinfo by UploadedFile, mbstring by Laravel's string
     * handling — so no direct symbol reference exists to detect. They stay
     * declared because a host missing them fails at runtime.
     */
    ->ignoreErrorsOnExtensions(
        ['ext-fileinfo', 'ext-gd', 'ext-mbstring'],
        [ErrorType::UNUSED_DEPENDENCY],
    )

    /*
     * The suite installs laravel/framework, which provides every Illuminate
     * namespace, so the analyser attributes those symbols there rather than to
     * the split packages this library actually requires.
     */
    ->ignoreErrorsOnPackages(
        ['illuminate/console', 'illuminate/encryption', 'illuminate/http', 'illuminate/support'],
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
