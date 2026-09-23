<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use LSNepomuceno\LaravelA1PdfSign\Agents\ToolCallLedger;

/**
 * The ledger that turns a repeated tool call into a single signature.
 *
 * Needs neither SDK: it is Laravel's cache and a key per call.
 */
it('lets exactly one caller claim an id', function () {
    $ledger = app(ToolCallLedger::class);

    expect($ledger->claim('call_1'))->toBeTrue()
        ->and($ledger->claim('call_1'))->toBeFalse()
        ->and($ledger->claim('call_2'))->toBeTrue();
});

it('has no result while the claiming call is still running', function () {
    $ledger = app(ToolCallLedger::class);
    $ledger->claim('call_1');

    expect($ledger->result('call_1'))->toBeNull();
});

it('hands the result of the first call to every repetition', function () {
    $ledger = app(ToolCallLedger::class);
    $ledger->claim('call_1');
    $ledger->complete('call_1', '{"signed":true}');

    expect($ledger->claim('call_1'))->toBeFalse()
        ->and($ledger->result('call_1'))->toBe('{"signed":true}');
});

it('frees an id whose call failed, so a retry may sign', function () {
    $ledger = app(ToolCallLedger::class);
    $ledger->claim('call_1');
    $ledger->release('call_1');

    expect($ledger->claim('call_1'))->toBeTrue();
});

it('writes to the store the application configured', function () {
    config()->set('cache.stores.ledger', ['driver' => 'array']);
    config()->set('a1-pdf-sign.agents.idempotency.store', 'ledger');

    app(ToolCallLedger::class)->claim('call_1');

    expect(Cache::store('ledger')->has('a1-pdf-sign:agents:tool-call:call_1'))->toBeTrue()
        ->and(Cache::store('array')->has('a1-pdf-sign:agents:tool-call:call_1'))->toBeFalse();
});

it('forgets an id once its time has passed', function () {
    config()->set('a1-pdf-sign.agents.idempotency.ttl', 60);

    $ledger = app(ToolCallLedger::class);
    $ledger->claim('call_1');

    $this->travel(61)->seconds();

    expect($ledger->claim('call_1'))->toBeTrue();
});

it('falls back to a day when the configured lifetime is nonsense', function (mixed $ttl) {
    config()->set('a1-pdf-sign.agents.idempotency.ttl', $ttl);

    $ledger = app(ToolCallLedger::class);
    $ledger->claim('call_1');

    $this->travel(86399)->seconds();
    expect($ledger->claim('call_1'))->toBeFalse();

    $this->travel(2)->seconds();
    expect($ledger->claim('call_1'))->toBeTrue();
})->with([[null], [0], [-5], ['soon']]);
