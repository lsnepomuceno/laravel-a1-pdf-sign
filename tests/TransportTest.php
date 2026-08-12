<?php

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use LSNepomuceno\LaravelA1PdfSign\Contracts\SignatureTransport;
use LSNepomuceno\LaravelA1PdfSign\Exceptions\SignatureTransportException;

/**
 * The one place this package reaches the network.
 *
 * It used to be `file_get_contents()` with a stream context, which meant a host
 * application could not `Http::fake()` it, could not apply its own proxy,
 * middleware or logging, and had no retry when a timestamp authority had a bad
 * minute. `Support\ProcessRunner` is built on Laravel's process factory so a
 * host can fake that; the network path had not been given the same treatment.
 *
 * Every test here fakes the client, so nothing in this file touches a real
 * authority. The live cross-check stays in the `network` group
 * (docs/decisions/0027-the-transport-is-a-seam.md).
 */
it('is intercepted by Http::fake, which is the whole point', function () {
    Http::fake(['tsa.example/*' => Http::response('a timestamp token')]);

    $token = app(SignatureTransport::class)->timestamp('https://tsa.example/tsr')('a request');

    expect($token)->toBe('a timestamp token');

    Http::assertSent(fn($request) => $request->url() === 'https://tsa.example/tsr'
        && $request->hasHeader('Content-Type', 'application/timestamp-query'));
});

it('sends basic credentials when the authority is configured with them', function () {
    Http::fake(['tsa.example/*' => Http::response('token')]);

    app(SignatureTransport::class)->timestamp('https://tsa.example/tsr', 'user', 'secret')('a request');

    Http::assertSent(fn($request) => $request->hasHeader(
        'Authorization',
        'Basic ' . base64_encode('user:secret'),
    ));
});

it('retries a transient failure rather than failing the signature', function () {
    config()->set('a1-pdf-sign.signature.timestamp.attempts', 3);
    config()->set('a1-pdf-sign.signature.timestamp.backoff', 0);

    Http::fakeSequence()
        ->push('', 500)
        ->push('', 500)
        ->push('a timestamp token', 200);

    expect(app(SignatureTransport::class)->timestamp('https://tsa.example/tsr')('a request'))
        ->toBe('a timestamp token');

    Http::assertSentCount(3);
});

it('gives up after the configured number of attempts, naming the URL', function () {
    config()->set('a1-pdf-sign.signature.timestamp.attempts', 2);
    config()->set('a1-pdf-sign.signature.timestamp.backoff', 0);

    Http::fake(['tsa.example/*' => Http::response('', 500)]);

    try {
        app(SignatureTransport::class)->timestamp('https://tsa.example/tsr')('a request');
    } catch (SignatureTransportException $exception) {
        // Named for the network. It used to be ProcessRunTimeException, which
        // named a fault that did not occur: no process is run to fetch a
        // timestamp.
        expect($exception->url)->toBe('https://tsa.example/tsr')
            ->and($exception->getMessage())->toContain('tsa.example');

        Http::assertSentCount(2);

        return;
    }

    expect(false)->toBeTrue();
});

it('keeps the body of a rejection, because RFC 3161 puts the reason in it', function () {
    // A TimeStampResp carrying a rejection is more useful than a status code,
    // and reading it is what `ignore_errors` was doing in the stream context.
    config()->set('a1-pdf-sign.signature.timestamp.attempts', 1);

    Http::fake(['tsa.example/*' => Http::response('a rejection with a reason', 400)]);

    expect(app(SignatureTransport::class)->timestamp('https://tsa.example/tsr')('a request'))
        ->toBe('a rejection with a reason');
});

it('degrades rather than failing when a revocation responder is unreachable', function () {
    // Revocation material improves the profile; an unreachable responder must
    // not fail the signature (docs/decisions/0024-revocation-is-evaluated-not-counted.md).
    config()->set('a1-pdf-sign.signature.ltv.attempts', 1);

    Http::fake(fn() => throw new ConnectionException('could not connect'));

    expect(app(SignatureTransport::class)->ocsp()('https://ocsp.example', 'a request'))->toBeFalse()
        ->and(app(SignatureTransport::class)->crl()('https://crl.example/list.crl'))->toBeFalse();
});

it('returns a CRL the distribution point answered with', function () {
    Http::fake(['crl.example/*' => Http::response('DER bytes')]);

    expect(app(SignatureTransport::class)->crl()('https://crl.example/list.crl'))->toBe('DER bytes');
});

it('treats a CRL the distribution point refused as absent', function () {
    Http::fake(['crl.example/*' => Http::response('not found', 404)]);

    expect(app(SignatureTransport::class)->crl()('https://crl.example/list.crl'))->toBeFalse();
});
