<?php

declare(strict_types=1);

namespace LSNepomuceno\LaravelA1PdfSign;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use LSNepomuceno\LaravelA1PdfSign\Adapters\{IlluminateProcessRunner, IlluminateSignatureTransport};
use LSNepomuceno\LaravelA1PdfSign\Commands\{AddSignatureFieldCommand,
    CheckEnvironmentCommand,
    ExtendArchiveCommand,
    ListSignatureFieldsCommand,
    SignPdfCommand,
    ValidatePdfSignatureCommand};
use LSNepomuceno\LaravelA1PdfSign\Config\SignetConfigFactory;
use LSNepomuceno\LaravelA1PdfSign\Contracts\A1PdfSign;
use LSNepomuceno\Signet\Contracts\{CertificateReader, PdfSigner, ProcessRunner, SealRenderer, SignatureTransport, SignatureValidator, SignatureVerifier};
use LSNepomuceno\Signet\Signet;

class LaravelA1PdfSignServiceProvider extends ServiceProvider
{
    private const string CONFIG_PATH = __DIR__ . '/../config/a1-pdf-sign.php';

    public function register(): void
    {
        $this->mergeConfigFrom(self::CONFIG_PATH, 'a1-pdf-sign');

        // The engine, assembled once from the config file. Everything else in
        // this provider either points at it or replaces one of its defaults
        // with Laravel's own infrastructure
        // (docs/decisions/0039-the-core-lives-in-signet-pdf.md).
        $this->app->singleton(
            Signet::class,
            static fn(Application $app): Signet => new Signet(
                config: $app->make(SignetConfigFactory::class)->make(),
                processes: $app->make(IlluminateProcessRunner::class),
                transport: new IlluminateSignatureTransport(
                    $app->make(\Illuminate\Http\Client\Factory::class),
                    $app->make(SignetConfigFactory::class)->make()->signing,
                ),
            ),
        );

        // Bound to the contract rather than the concrete class, so consuming
        // applications and tests can swap the implementation.
        $this->app->singleton(A1PdfSign::class, A1PdfSignManager::class);

        // signet-pdf's contracts, resolvable so an application can type-hint
        // them and so a test can replace one. They are accessors on the engine
        // rather than separate bindings, which is what keeps a swap on the
        // singleton visible everywhere.
        $this->app->bind(PdfSigner::class, static fn(Application $app): PdfSigner => $app->make(Signet::class)->signer());
        $this->app->bind(SignatureValidator::class, static fn(Application $app): SignatureValidator => $app->make(Signet::class)->validator());
        $this->app->bind(SignatureVerifier::class, static fn(Application $app): SignatureVerifier => $app->make(Signet::class)->verifier());
        $this->app->bind(SealRenderer::class, static fn(Application $app): SealRenderer => $app->make(Signet::class)->sealRenderer());
        $this->app->bind(CertificateReader::class, static fn(Application $app): CertificateReader => $app->make(Signet::class)->certificateReader());
        $this->app->bind(SignatureTransport::class, static fn(Application $app): SignatureTransport => $app->make(Signet::class)->transport());
        $this->app->bind(ProcessRunner::class, static fn(Application $app): ProcessRunner => $app->make(Signet::class)->processes());
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                self::CONFIG_PATH => $this->app->configPath('a1-pdf-sign.php'),
            ], 'a1-pdf-sign-config');

            $this->commands([
                AddSignatureFieldCommand::class,
                CheckEnvironmentCommand::class,
                ExtendArchiveCommand::class,
                ListSignatureFieldsCommand::class,
                SignPdfCommand::class,
                ValidatePdfSignatureCommand::class,
            ]);
        }
    }
}
