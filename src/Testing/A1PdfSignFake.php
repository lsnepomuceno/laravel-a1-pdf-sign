<?php

declare(strict_types=1);

namespace LSNepomuceno\LaravelA1PdfSign\Testing;

use Illuminate\Container\Container;
use LSNepomuceno\LaravelA1PdfSign\Contracts\A1PdfSign;
use LSNepomuceno\Signet\Contracts\{CertificateReader, PdfSigner};
use LSNepomuceno\Signet\Data\Certificate;
use LSNepomuceno\Signet\Enums\{CertificationLevel, SignatureProfile};
use LSNepomuceno\Signet\Signet;
use LSNepomuceno\Signet\Testing\{FakeCertificateReader, FakePdfSigner};

/**
 * What a consuming application asserts about its own signing.
 *
 * An application that signs PDFs has to test the code path that signs PDFs,
 * and doing that for real means a PKCS#12 bundle in somebody else's repository
 * and a real CMS built for every test that happens to touch the flow.
 *
 * The recorder and its assertions are signet-pdf's `Testing\FakePdfSigner`.
 * What this adds is the Laravel half: installing it into the container so that
 * the facade, the manager and anything resolving the engine all reach the fake
 * rather than only the caller who built it.
 *
 * Install it with `A1PdfSign::fake()`, which returns this.
 */
final readonly class A1PdfSignFake
{
    public function __construct(private FakePdfSigner $signer) {}

    /**
     * Replaces the engine in the container with one that signs nothing.
     *
     * It rebuilds `Signet` rather than rebinding `PdfSigner`, because the
     * engine resolves its own signer: swapping the contract alone would leave
     * `newSignature()->…->sign()` reaching the real one. The manager is a
     * singleton holding the engine, so it is forgotten here and rebuilt on
     * next resolution.
     */
    public static function install(Container $container): self
    {
        $signer = new FakePdfSigner();
        $reader = new FakeCertificateReader();

        $engine = $container->make(Signet::class);

        $container->instance(Signet::class, new Signet(
            config: $engine->config,
            signer: $signer,
            certificateReader: $reader,
        ));

        $container->instance(PdfSigner::class, $signer);
        $container->instance(CertificateReader::class, $reader);
        $container->forgetInstance(A1PdfSign::class);

        return new self($signer);
    }

    /**
     * A certificate that opens nothing, for the builder's guard.
     *
     * `sign()` refuses without one, and the fake signer never looks at it, so
     * this exists to let the flow run rather than to represent anything. It
     * generates no key: `Signet\Testing\DebugCertificate` does, and paying for
     * real key generation in every test that merely passes through a signing
     * call is what this fake exists to avoid.
     */
    public static function certificate(): Certificate
    {
        return new Certificate('faked, not a certificate', false, [], '');
    }

    public function assertSigned(?string $contains = null): void
    {
        $this->signer->assertSigned($contains);
    }

    public function assertSignedTimes(int $times): void
    {
        $this->signer->assertSignedTimes($times);
    }

    public function assertNothingSigned(): void
    {
        $this->signer->assertNothingSigned();
    }

    public function assertSignedWithProfile(SignatureProfile $profile): void
    {
        $this->signer->assertSignedWithProfile($profile);
    }

    public function assertCertified(?CertificationLevel $level = null): void
    {
        $this->signer->assertCertified($level);
    }

    public function assertSealed(): void
    {
        $this->signer->assertSealed();
    }

    /**
     * Two-phase signing, where the private key never enters the process.
     */
    public function assertPrepared(int $times = 1): void
    {
        $this->signer->assertPrepared($times);
    }

    public function assertCompleted(?string $cms = null): void
    {
        $this->signer->assertCompleted($cms);
    }
}
