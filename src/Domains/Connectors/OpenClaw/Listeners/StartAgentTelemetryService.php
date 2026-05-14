<?php

declare(strict_types=1);

namespace Kanvas\Connectors\OpenClaw\Listeners;

use Illuminate\Support\Facades\Cache;
use Kanvas\Connectors\OpenClaw\Services\AgentTelemetryService;
use Laravel\Octane\Events\WorkerStarting;
use Swoole\Timer;

class StartAgentTelemetryService
{
    /**
     * TTL slightly longer than the retry interval so the lock is always held
     * by the active worker, but expires quickly after an unclean shutdown.
     */
    private const LOCK_TTL = 70;   // slightly above the 65s collect() lock TTL
    private const RETRY_MS = 5_000;

    public function handle(WorkerStarting $event): void
    {
        // Try immediately — if we win the race on first boot, start right away.
        if (Cache::add('openclaw:telemetry:worker', getmypid(), self::LOCK_TTL)) {
            app(AgentTelemetryService::class)->start();

            return;
        }

        // Otherwise poll every RETRY_MS until the orphaned lock expires and we can take over.
        $timerId = null;
        $timerId = Timer::tick(self::RETRY_MS, function () use (&$timerId) {
            if (Cache::add('openclaw:telemetry:worker', getmypid(), self::LOCK_TTL)) {
                if ($timerId !== null) {
                    Timer::clear($timerId);
                }
                app(AgentTelemetryService::class)->start();
            }
        });
    }
}
