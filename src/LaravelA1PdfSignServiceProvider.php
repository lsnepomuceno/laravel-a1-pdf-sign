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
        $this->mergeConfigFrom(self::CONFIG_PATH, 'a1-pdf-sign');

        // Bound to the contract rather than the concrete class, so consuming
        // applications and tests can swap the implementation.
        $this->app->singleton(A1PdfSign::class, A1PdfSignManager::class);
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
