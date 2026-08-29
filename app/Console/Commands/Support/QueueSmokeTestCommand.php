<?php

declare(strict_types=1);

namespace App\Console\Commands\Support;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Kanvas\Jobs\QueueSmokeTestJob;

/**
 * End-to-end smoke test for the queue environment: dispatch a probe job onto
 * a real queue connection/queue and confirm a worker actually processed it.
 *
 * This is the fastest way to answer "is `queue:work --queue=foo` actually
 * consuming jobs?" without digging through `kanvas:status` pending counts or
 * waiting for a real business job to fail silently.
 *
 * Usage:
 *   php artisan kanvas:queue-test
 *   php artisan kanvas:queue-test --queue=workflow --timeout=15
 *   php artisan kanvas:queue-test --connection=redis --queue=agent-runtime
 *   php artisan kanvas:queue-test --dispatch-only
 *
 * See docs/tetsuo_console_exploration.md for the full write-up.
 */
class QueueSmokeTestCommand extends Command
{
    protected $signature = 'kanvas:queue-test
        {--connection= : Queue connection to dispatch on (defaults to QUEUE_CONNECTION)}
        {--queue=default : Queue name to dispatch the probe job to}
        {--timeout=10 : Seconds to wait for a worker to pick up the job}
        {--dispatch-only : Dispatch the probe job and exit immediately, without waiting}';

    protected $description = 'Dispatch a probe job onto a queue and confirm a worker processed it';

    public function handle(): int
    {
        $connection = $this->option('connection') ?: config('queue.default');
        $queue = (string) $this->option('queue');
        $timeout = max(1, (int) $this->option('timeout'));
        $token = (string) Str::uuid();
        $dispatchedAt = now()->toIso8601String();

        $this->info("Dispatching probe job [{$token}] onto connection=\"{$connection}\" queue=\"{$queue}\"...");

        QueueSmokeTestJob::dispatch($token, $dispatchedAt)
            ->onConnection($connection)
            ->onQueue($queue);

        if ($this->option('dispatch-only')) {
            $this->line('Dispatched only (--dispatch-only). Check the cache key below manually:');
            $this->line('  ' . QueueSmokeTestJob::cacheKey($token));

            return self::SUCCESS;
        }

        $cacheKey = QueueSmokeTestJob::cacheKey($token);
        $start = microtime(true);

        while ((microtime(true) - $start) < $timeout) {
            $marker = Cache::get($cacheKey);

            if ($marker !== null) {
                $elapsedMs = (int) round((microtime(true) - $start) * 1000);
                $this->newLine();
                $this->info("✓ Job processed after {$elapsedMs} ms by worker on host \"{$marker['hostname']}\".");
                $this->table(
                    ['Token', 'Connection', 'Queue', 'Dispatched at', 'Processed at'],
                    [[
                        $marker['token'],
                        $marker['connection'],
                        $marker['queue'],
                        $marker['dispatched_at'],
                        $marker['processed_at'],
                    ]]
                );

                return self::SUCCESS;
            }

            usleep(200_000); // 200ms poll interval
        }

        $this->newLine();
        $this->error("✗ No worker processed the job within {$timeout}s.");
        $this->warn(
            "Make sure a worker is listening on this queue, e.g.:\n" .
            "  php artisan queue:work --connection={$connection} --queue={$queue}"
        );

        return self::FAILURE;
    }
}
