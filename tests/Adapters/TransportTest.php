<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use LSNepomuceno\Signet\Contracts\SignatureTransport;
use LSNepomuceno\Signet\Enums\SignatureProfile;
use LSNepomuceno\Signet\Exceptions\SignatureTransportException;
use LSNepomuceno\Signet\Signet;

it('reaches the timestamp authority through Laravel, so a fake intercepts it', function () {
    Http::fake(['tsa.example/*' => Http::response('a timestamp response', 200)]);

    $answer = app(SignatureTransport::class)->timestamp('https://tsa.example/tsr')('a request');

    expect($answer)->toBe('a timestamp response');

    Http::assertSent(fn($request): bool => $request->url() === 'https://tsa.example/tsr'
        && $request->body() === 'a request');
});

it('carries basic auth when the authority asks for it', function () {
    Http::fake(['tsa.example/*' => Http::response('signed', 200)]);

    app(SignatureTransport::class)->timestamp('https://tsa.example/tsr', 'user', 'secret')('a request');

    Http::assertSent(fn($request): bool => $request->hasHeader(
        'Authorization',
        'Basic ' . base64_encode('user:secret'),
    ) === true);
});

it('reports an authority that answered with nothing', function () {
    Http::fake(['tsa.example/*' => Http::response('', 502)]);

    app(SignatureTransport::class)->timestamp('https://tsa.example/tsr')('a request');
})->throws(SignatureTransportException::class);

it('degrades rather than fails when a revocation responder is unreachable', function () {
    // The difference between the two policies: a timestamp authority failing
    // fails the signature, and an OCSP responder failing only means less
    // material is embedded.
    Http::fake(['ocsp.example/*' => Http::response('', 500)]);

    expect(app(SignatureTransport::class)->ocsp()('https://ocsp.example', 'a request'))->toBeFalse();
});

it('reaches no network at all for a signature that needs none', function () {
    Http::preventStrayRequests();

    [$pfxPath, $password] = debugCertificate();

    $signed = app(Signet::class)->newSignature()
        ->certificate($pfxPath, $password)
        ->pdf(resource('test.pdf'))
        ->profile(SignatureProfile::PadesBB)
        ->sign();

    expect($signed->contents)->toContain('/ETSI.CAdES.detached');
});
