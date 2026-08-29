<?php

declare(strict_types=1);

namespace Kanvas\Jobs;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;

/**
 * A no-op probe job used to verify that a given queue connection/queue is
 * actually being drained by a worker.
 *
 * `kanvas:queue-test` dispatches this job with a unique token and then polls
 * the cache key it writes here. If the key never appears, no worker picked
 * the job up (wrong `--queue` name, worker not running, broker unreachable).
 *
 * See `docs/tetsuo_console_exploration.md` for the full write-up.
 */
class QueueSmokeTestJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use KanvasJobsTrait;

    public $tries = 1;

    /**
     * How long the "processed" marker survives in cache after the job runs.
     */
    public const int MARKER_TTL_SECONDS = 300;

    public function __construct(
        public string $token,
        public string $dispatchedAt,
    ) {
    }

    public function handle(): void
    {
        Cache::put(
            self::cacheKey($this->token),
            [
                'token' => $this->token,
                'dispatched_at' => $this->dispatchedAt,
                'processed_at' => now()->toIso8601String(),
                'queue' => $this->queue ?? 'default',
                'connection' => $this->connection ?? config('queue.default'),
                'hostname' => gethostname() ?: 'unknown',
            ],
            self::MARKER_TTL_SECONDS
        );
    }

    public static function cacheKey(string $token): string
    {
        return 'kanvas:queue-smoke-test:' . $token;
    }
}
