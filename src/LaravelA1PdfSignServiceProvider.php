<?php

declare(strict_types=1);

namespace LSNepomuceno\LaravelA1PdfSign;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use LSNepomuceno\LaravelA1PdfSign\Certificates\ReaderFactory;
use LSNepomuceno\LaravelA1PdfSign\Commands\{CheckEnvironmentCommand, SignPdfCommand, ValidatePdfSignatureCommand};
use LSNepomuceno\LaravelA1PdfSign\Contracts\A1PdfSign;
use LSNepomuceno\LaravelA1PdfSign\Contracts\CertificateReader;
use LSNepomuceno\LaravelA1PdfSign\Contracts\PdfSigner;
use LSNepomuceno\LaravelA1PdfSign\Contracts\SealRenderer;
use LSNepomuceno\LaravelA1PdfSign\Contracts\SignatureTransport;
use LSNepomuceno\LaravelA1PdfSign\Contracts\SignatureValidator;
use LSNepomuceno\LaravelA1PdfSign\Seal\InterventionSealRenderer;
use LSNepomuceno\LaravelA1PdfSign\Signing\Cades\HttpTransport;
use LSNepomuceno\LaravelA1PdfSign\Signing\IncrementalSigner;
use LSNepomuceno\LaravelA1PdfSign\Validation\PdfSignatureValidator;
use LSNepomuceno\LaravelA1PdfSign\Validation\TrustVerifier;

class LaravelA1PdfSignServiceProvider extends ServiceProvider
{
    private const string CONFIG_PATH = __DIR__ . '/../config/a1-pdf-sign.php';

    public function register(): void
    {
        $this->mergeConfigFrom(self::CONFIG_PATH, 'a1-pdf-sign');

        // Bound to the contract rather than the concrete class, so consuming
        // applications and tests can swap the implementation.
        $this->app->singleton(A1PdfSign::class, A1PdfSignManager::class);

        // The incremental signer is the default: it preserves the original
        // bytes and lets a document carry more than one signature (§3h).
        $this->app->bind(PdfSigner::class, IncrementalSigner::class);
        $this->app->bind(SealRenderer::class, InterventionSealRenderer::class);
        $this->app->bind(SignatureValidator::class, PdfSignatureValidator::class);
        // The seam invariant 9 is built on: everything the profiles above
        // pades-b-b add rides through here, and a test that can replace it
        // turns them from reported into gated
        // (docs/decisions/0027-the-transport-is-a-seam.md).
        $this->app->bind(SignatureTransport::class, HttpTransport::class);

        // Bound to itself on purpose, and it must stay bound. PdfSignatureValidator
        // takes it as an optional parameter so its arity does not move, and the
        // container only resolves a defaulted class-typed parameter when the
        // class is bound: without this line the validator silently gets null
        // and every signature reports trust as unknown
        // (docs/decisions/0016-trust-is-the-applications-policy.md).
        $this->app->bind(TrustVerifier::class);

        $this->app->bind(
            CertificateReader::class,
            static fn(Application $app): CertificateReader => $app->make(ReaderFactory::class)->make(),
        );
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                self::CONFIG_PATH => $this->app->configPath('a1-pdf-sign.php'),
            ], 'a1-pdf-sign-config');

            $this->commands([
                CheckEnvironmentCommand::class,
                SignPdfCommand::class,
                ValidatePdfSignatureCommand::class,
            ]);
        }
    }
}
