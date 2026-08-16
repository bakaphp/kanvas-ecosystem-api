<?php

declare(strict_types=1);

namespace Kanvas\Auth\Services;

use Illuminate\Support\Facades\Cache;
use Kanvas\Apps\Models\Apps;

/**
 * Per-app fixed-window counters for the signup abuse rules.
 *
 * Keys are namespaced by app id, so one tenant's traffic can never trip
 * another's limit.
 */
final class SignupCounterService
{
    public function __construct(
        private readonly Apps $app,
    ) {
    }

    /**
     * `add` only seeds the counter when the key is absent, so the TTL is
     * anchored to the first hit of the window and a sustained trickle still
     * expires rather than sliding forward forever.
     */
    public function hit(string $bucket, int $ttlSeconds): int
    {
        $key = $this->key($bucket);

        Cache::add($key, 0, $ttlSeconds);

        return (int) Cache::increment($key);
    }

    public function read(string $bucket): int
    {
        return (int) (Cache::get($this->key($bucket)) ?? 0);
    }

    /**
     * Claims a one-shot slot, returning false when one is already held. Used to
     * throttle reporting rather than to count.
     */
    public function claim(string $bucket, int $ttlSeconds): bool
    {
        return Cache::add($this->key($bucket), true, $ttlSeconds);
    }

    private function key(string $bucket): string
    {
        return 'signup_counter:' . $this->app->getId() . ':' . md5($bucket);
    }
}
