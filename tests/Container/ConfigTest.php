<?php

declare(strict_types=1);

use LSNepomuceno\LaravelA1PdfSign\Config\SignetConfigFactory;
use LSNepomuceno\Signet\Enums\{DigestAlgorithm, ImageDriver, SignatureProfile};
use LSNepomuceno\Signet\IcpBrasil\Enums\SignaturePolicy;
use LSNepomuceno\Signet\Signet;

function builtConfig(): LSNepomuceno\Signet\Config\SignetConfig
{
    return app(SignetConfigFactory::class)->make();
}

it('builds the engine config out of the config file', function () {
    config()->set('a1-pdf-sign.signature.profile', 'pades-b-lt');
    config()->set('a1-pdf-sign.signature.digest_algorithm', 'sha512');
    config()->set('a1-pdf-sign.signature.timestamp.url', 'https://tsa.example/tsr');
    config()->set('a1-pdf-sign.signature.timestamp.attempts', 5);
    config()->set('a1-pdf-sign.certificate.legacy', true);

    $built = builtConfig();

    expect($built->signing->profile)->toBe(SignatureProfile::PadesBLT)
        ->and($built->signing->digest)->toBe(DigestAlgorithm::Sha512)
        ->and($built->signing->timestamp->url)->toBe('https://tsa.example/tsr')
        ->and($built->signing->timestamp->attempts)->toBe(5)
        ->and($built->certificate->legacy)->toBeTrue()
        ->and($built->seal->driver)->toBe(ImageDriver::Gd);
});

it('names the key when a value is not one the engine accepts', function () {
    config()->set('a1-pdf-sign.signature.profile', 'pades-b-xx');

    builtConfig();
})->throws(InvalidArgumentException::class, 'a1-pdf-sign.signature.profile is [pades-b-xx]');

it('declares no policy unless one is configured', function () {
    expect(builtConfig()->signing->policy)->toBeNull();
});

it('resolves a policy named by its family to the version in force', function () {
    config()->set('a1-pdf-sign.signature.policy', 'ad-rt');

    $policy = builtConfig()->signing->policy;

    expect($policy)->not->toBeNull()
        ->and($policy->oid)->toBe(SignaturePolicy::forProfile(SignatureProfile::PadesBT)?->value);
});

it('takes a policy from anywhere when it is given in full', function () {
    config()->set('a1-pdf-sign.signature.policy', [
        'oid' => '1.2.3.4',
        'digest_algorithm' => 'sha256',
        'digest' => 'abc123',
        'uri' => 'https://policies.example/one.der',
    ]);

    $policy = builtConfig()->signing->policy;

    expect($policy?->oid)->toBe('1.2.3.4')
        ->and($policy?->uri)->toBe('https://policies.example/one.der');
});

it('refuses a policy it cannot resolve rather than signing without one', function () {
    config()->set('a1-pdf-sign.signature.policy', 'ad-xx');

    builtConfig();
})->throws(InvalidArgumentException::class, 'does not name a known policy');

it('carries the configured chain paths through to the engine', function () {
    config()->set('a1-pdf-sign.certificate.chain_paths', ['/ca/intermediate.pem', 42, '/ca/root.pem']);

    // The non-string is dropped rather than cast: a path is a path.
    expect(builtConfig()->certificate->chainPaths)->toBe(['/ca/intermediate.pem', '/ca/root.pem']);
});

it('survives config:cache, because the file holds no objects', function () {
    // The objects are built at resolution; the file is scalars. A config file
    // carrying an enum instance would fail to cache, and it would fail in the
    // consuming application rather than here.
    expect(serialize(config('a1-pdf-sign')))->toBeString()
        ->and(app(Signet::class)->config->signing->profile)->toBeInstanceOf(SignatureProfile::class);
});
