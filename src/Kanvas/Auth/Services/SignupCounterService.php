<?php

declare(strict_types=1);

namespace Kanvas\Auth\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Kanvas\Apps\Models\Apps;

/**
 * Per-app counters for the signup abuse rules, in two shapes: `hit` for a
 * caller that names its own period, `hitInWindow` for a window that slides with
 * the clock.
 *
 * Keys are namespaced by app id, so one tenant's traffic can never trip
 * another's limit.
 */
final class SignupCounterService
{
    /**
     * How many slices a sliding window is cut into. Each slice holds the hits
     * that landed inside it and the window is their sum, so the count ages out
     * one slice at a time instead of vanishing all at once. The trade is
     * granularity against cache reads: twelve costs twelve reads per count and
     * over-reports by at most a twelfth of the window.
     */
    private const WINDOW_SLICES = 12;

    public function __construct(
        private readonly Apps $app,
    ) {
    }

    /**
     * Counter whose TTL is pure retention — the caller owns the bucketing by
     * encoding the period into the bucket name (`blocked:2026-08-28T14`).
     */
    public function hit(string $bucket, int $ttlSeconds): int
    {
        $key = $this->key($bucket);

        Cache::add($key, 0, $ttlSeconds);

        return (int) Cache::increment($key);
    }

    /**
     * Records the attempt and answers how many landed in the trailing
     * `$windowSeconds`.
     *
     * The window has to slide. A counter whose TTL is anchored to its first hit
     * expires between the hits of a slow drip, so a campaign spaced wider than
     * the window sits at a count of 1 no matter how long it runs.
     */
    public function hitInWindow(string $bucket, int $windowSeconds): int
    {
        $sliceSeconds = $this->sliceSeconds($windowSeconds);
        $key = $this->sliceKey($bucket, $this->currentSlice($sliceSeconds));

        /**
         * Slices are read for a whole window after they stop being written, so
         * they have to outlive the window itself by one slice.
         */
        Cache::add($key, 0, $windowSeconds + $sliceSeconds);
        Cache::increment($key);

        return $this->readWindow($bucket, $windowSeconds);
    }

    private function readWindow(string $bucket, int $windowSeconds): int
    {
        $sliceSeconds = $this->sliceSeconds($windowSeconds);
        $current = $this->currentSlice($sliceSeconds);
        $total = 0;

        for ($offset = 0; $offset < self::WINDOW_SLICES; $offset++) {
            $total += (int) (Cache::get($this->sliceKey($bucket, $current - $offset)) ?? 0);
        }

        return $total;
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

    private function sliceKey(string $bucket, int $slice): string
    {
        return $this->key($bucket) . ':' . $slice;
    }

    /**
     * Slices are cut from the epoch rather than from the first hit, so every
     * process agrees on which slice `now` belongs to without coordinating.
     */
    private function currentSlice(int $sliceSeconds): int
    {
        return intdiv(Carbon::now()->getTimestamp(), $sliceSeconds);
    }

    private function sliceSeconds(int $windowSeconds): int
    {
        return max(1, (int) ceil($windowSeconds / self::WINDOW_SLICES));
    }
}
