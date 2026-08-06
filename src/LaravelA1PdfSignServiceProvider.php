<?php

namespace LSNepomuceno\LaravelA1PdfSign;

use Illuminate\Support\ServiceProvider;
use LSNepomuceno\LaravelA1PdfSign\Commands\{SignPdfCommand, ValidatePdfSignatureCommand};
use LSNepomuceno\LaravelA1PdfSign\Contracts\A1PdfSign;

class LaravelA1PdfSignServiceProvider extends ServiceProvider
{
    private const string CONFIG_PATH = __DIR__ . '/../config/a1-pdf-sign.php';

    public function register(): void
    {
        $this->registerFontPath();
        $this->mergeConfigFrom(self::CONFIG_PATH, 'a1-pdf-sign');

        // Bound to the contract rather than the concrete class, so consuming
        // applications and tests can swap the implementation.
        $this->app->singleton(A1PdfSign::class, A1PdfSignManager::class);
    }

    /**
     * tc-lib-pdf resolves fonts through the K_PATH_FONTS constant and cannot
     * emit any PDF without one, yet ships no font files. The package bundles
     * the Core 14 metrics it needs; see resources/fonts/README.md and
     * ARCHITECTURE-V2.md §3g.2.
     *
     * An application that defined the constant first keeps its own directory.
     */
    private function registerFontPath(): void
    {
        if (! defined('K_PATH_FONTS')) {
            define('K_PATH_FONTS', dirname(__DIR__) . '/resources/fonts');
        }
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                self::CONFIG_PATH => $this->app->configPath('a1-pdf-sign.php'),
            ], 'a1-pdf-sign-config');

            $this->commands([
                SignPdfCommand::class,
                ValidatePdfSignatureCommand::class,
            ]);
        }
    }
}
