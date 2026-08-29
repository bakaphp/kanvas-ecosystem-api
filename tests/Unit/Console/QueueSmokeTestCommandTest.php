<?php

declare(strict_types=1);

namespace Tests\Unit\Console;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Kanvas\Jobs\QueueSmokeTestJob;
use Tests\TestCaseUnit;

/**
 * Covers the `kanvas:queue-test` console command and its `QueueSmokeTestJob`
 * probe — the pair documented in `docs/tetsuo_console_exploration.md` for
 * verifying that a queue connection is actually being drained by a worker.
 */
final class QueueSmokeTestCommandTest extends TestCaseUnit
{
    public function testDispatchOnlyDoesNotWaitForAWorker(): void
    {
        Queue::fake();

        $this->artisan('kanvas:queue-test', ['--dispatch-only' => true])
            ->assertSuccessful();

        Queue::assertPushedOn('default', QueueSmokeTestJob::class);
    }

    public function testDispatchOnlyHonorsCustomQueueOption(): void
    {
        Queue::fake();

        $this->artisan('kanvas:queue-test', ['--queue' => 'workflow', '--dispatch-only' => true])
            ->assertSuccessful();

        Queue::assertPushedOn('workflow', QueueSmokeTestJob::class);
    }

    public function testCommandSucceedsWhenAWorkerProcessesTheJobInline(): void
    {
        // QUEUE_CONNECTION=sync in the test env (phpunit.xml), so dispatching
        // the probe job runs it synchronously — the same as a real worker
        // picking it up immediately.
        $this->artisan('kanvas:queue-test', ['--timeout' => 2])
            ->assertSuccessful();
    }

    public function testCommandFailsWhenNoWorkerEverProcessesTheJob(): void
    {
        // Faking the queue means the job is recorded but never actually run,
        // simulating a missing/stalled worker.
        Queue::fake();

        $this->artisan('kanvas:queue-test', ['--timeout' => 1])
            ->assertFailed();
    }

    public function testJobHandleWritesAProcessedMarkerToCache(): void
    {
        $token = (string) Str::uuid();
        $dispatchedAt = now()->toIso8601String();

        new QueueSmokeTestJob($token, $dispatchedAt)->handle();

        $marker = Cache::get(QueueSmokeTestJob::cacheKey($token));

        $this->assertNotNull($marker);
        $this->assertSame($token, $marker['token']);
        $this->assertSame($dispatchedAt, $marker['dispatched_at']);
        $this->assertArrayHasKey('processed_at', $marker);
    }
}
