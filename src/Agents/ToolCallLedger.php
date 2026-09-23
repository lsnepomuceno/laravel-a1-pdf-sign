<?php

declare(strict_types=1);

namespace LSNepomuceno\LaravelA1PdfSign\Agents;

use Illuminate\Contracts\Cache\Factory as Caches;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Config\Repository as Config;

/**
 * Which tool calls have already signed, so a repeated call signs nothing.
 *
 * **A signature is not idempotent**: signing the same document twice produces
 * two signed copies, and each is a legal act. After a human approves a call,
 * the call can still arrive twice: a provider retries on a timeout, a queued
 * run is picked up again, a user double-clicks "approve". Each repetition
 * carries the same provider tool-call id, which the AI SDK hands the tool as
 * `Request::toolCallId()` and describes as "usable as an external idempotency
 * key".
 *
 * This is that key's ledger, in the application's own cache. The first call
 * claims the id with `add()`, which does nothing when the key exists; a second
 * call finds it and gets back what the first one produced, or learns that the
 * first is still running
 * (docs/decisions/0040-agents-read-through-mcp-and-sign-through-the-ai-sdk.md).
 *
 * **The claim is exactly as atomic as the cache store.** Redis, Memcached,
 * DynamoDB and the database store implement `add()` atomically. The file and
 * array stores do not, which is why the store is configurable rather than
 * whatever the application's default happens to be.
 */
final readonly class ToolCallLedger
{
    private const string PREFIX = 'a1-pdf-sign:agents:tool-call:';

    /**
     * Stored while the call runs. Not a value a result can ever take, since a
     * result is JSON and JSON never starts with a null byte.
     */
    private const string RUNNING = "\0running";

    public function __construct(
        private Config $config,
        private Caches $caches,
    ) {}

    /**
     * Claims the id for this call, and answers whether the claim was won.
     */
    public function claim(string $toolCallId): bool
    {
        return $this->cache()->add(self::PREFIX . $toolCallId, self::RUNNING, $this->ttl());
    }

    /**
     * What the call that holds the id produced, or null while it is running.
     */
    public function result(string $toolCallId): ?string
    {
        $value = $this->cache()->get(self::PREFIX . $toolCallId);

        return is_string($value) && $value !== self::RUNNING ? $value : null;
    }

    public function complete(string $toolCallId, string $result): void
    {
        $this->cache()->put(self::PREFIX . $toolCallId, $result, $this->ttl());
    }

    /**
     * Gives the id up, after a call that failed before anything was written,
     * so a retry is free to sign.
     */
    public function release(string $toolCallId): void
    {
        $this->cache()->forget(self::PREFIX . $toolCallId);
    }

    private function cache(): Cache
    {
        $store = $this->config->get('a1-pdf-sign.agents.idempotency.store');

        return $this->caches->store(is_string($store) && $store !== '' ? $store : null);
    }

    private function ttl(): int
    {
        $ttl = $this->config->get('a1-pdf-sign.agents.idempotency.ttl');

        return is_numeric($ttl) && (int) $ttl > 0 ? (int) $ttl : 86400;
    }
}
