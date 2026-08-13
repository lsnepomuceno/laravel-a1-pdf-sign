<?php

declare(strict_types=1);

namespace LSNepomuceno\LaravelA1PdfSign\Testing;

use Illuminate\Container\Container;
use LSNepomuceno\LaravelA1PdfSign\Contracts\CertificateReader;
use LSNepomuceno\LaravelA1PdfSign\Contracts\PdfSigner;
use LSNepomuceno\LaravelA1PdfSign\Data\Certificate;
use LSNepomuceno\LaravelA1PdfSign\Enums\{CertificationLevel, SignatureProfile};
use PHPUnit\Framework\Assert;

/**
 * What a consuming application asserts about its own signing.
 *
 * An application that signs PDFs has to test the code path that signs PDFs,
 * and doing that for real means a PKCS#12 bundle in somebody else's repository
 * and a real CMS built for every test that happens to touch the flow.
 *
 * The mechanism was already there, since everything resolves through the
 * container. What was missing is knowing **what to assert**: the shape of the
 * result, which builder calls are terminal, what a profile is called. That is
 * what this encodes, and it stays correct when those shapes change, which a
 * hand-rolled double in another repository does not.
 *
 * Install it with `A1PdfSign::fake()`, which returns this.
 */
final readonly class A1PdfSignFake
{
    public function __construct(private FakePdfSigner $signer) {}

    /**
     * Replaces the signer in the container and hands back the recorder.
     */
    public static function install(Container $container): self
    {
        $signer = new FakePdfSigner();

        $container->instance(PdfSigner::class, $signer);
        $container->instance(CertificateReader::class, new FakeCertificateReader());

        return new self($signer);
    }

    /**
     * A certificate that opens nothing, for the builder's guard.
     *
     * `sign()` refuses without one, and the fake signer never looks at it, so
     * this exists to let the flow run rather than to represent anything. It
     * generates no key: `Testing\DebugCertificate` does, and paying for real
     * key generation in every test that merely passes through a signing call is
     * what this fake exists to avoid.
     */
    public static function certificate(): Certificate
    {
        return new Certificate('faked, not a certificate', false, [], '');
    }

    /**
     * Something was signed, and optionally a document whose bytes contain the
     * given fragment.
     *
     * A fragment rather than a path, because the signer is handed bytes and
     * never learns where they came from. In practice a caller asserts on
     * something the document says.
     */
    public function assertSigned(?string $contains = null): void
    {
        Assert::assertNotEmpty($this->signer->signed, 'Expected a document to be signed, and none was.');

        if ($contains === null) {
            return;
        }

        $found = false;

        foreach ($this->signer->signed as $call) {
            $found = $found || str_contains($call['document'], $contains);
        }

        // assertTrue rather than an early return followed by fail(): a test
        // whose only path performs no assertion is reported as risky, which is
        // how the first version of this class behaved on success.
        Assert::assertTrue($found, "Expected a signed document containing [{$contains}], and none did.");
    }

    public function assertSignedTimes(int $times): void
    {
        Assert::assertCount($times, $this->signer->signed);
    }

    /**
     * The negative, which is usually the assertion that catches a bug.
     */
    public function assertNothingSigned(): void
    {
        Assert::assertSame([], $this->signer->signed, 'Expected nothing to be signed, and something was.');
    }

    /**
     * The profile was the one intended.
     *
     * Null means the caller left it to configuration, which is a different
     * statement from asking for the default explicitly, so it is asserted as
     * what it is rather than resolved here.
     */
    public function assertSignedWithProfile(SignatureProfile $profile): void
    {
        $found = false;

        foreach ($this->signer->signed as $call) {
            $found = $found || $call['profile'] === $profile;
        }

        Assert::assertTrue($found, "Expected a document signed at [{$profile->value}], and none was.");
    }

    /**
     * A certification, which has consequences a plain signature does not.
     */
    public function assertCertified(?CertificationLevel $level = null): void
    {
        $found = false;

        foreach ($this->signer->signed as $call) {
            $found = $found || ($call['certification'] !== null && ($level === null || $call['certification'] === $level));
        }

        Assert::assertTrue($found, $level === null
            ? 'Expected a certified document, and none was.'
            : "Expected a document certified at [{$level->value}], and none was.");
    }

    public function assertSealed(): void
    {
        $found = false;

        foreach ($this->signer->signed as $call) {
            $found = $found || $call['sealed'];
        }

        Assert::assertTrue($found, 'Expected a document signed with a visible seal, and none was.');
    }
}
