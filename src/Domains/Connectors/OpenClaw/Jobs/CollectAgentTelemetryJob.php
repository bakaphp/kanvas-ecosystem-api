<?php

declare(strict_types=1);

namespace Kanvas\Connectors\OpenClaw\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Kanvas\Connectors\OpenClaw\Services\AgentTelemetryService;
use Kanvas\Intelligence\Agents\Models\AgentDeployment;

/**
 * Queued job to collect live telemetry from one running OpenClaw deployment.
 *
 * Dispatched by AgentTelemetryService every 60 s from a Swoole timer.
 * Running SSH + parsing in a queue worker keeps the Swoole HTTP workers
 * free and gives us a clean 60-second hard timeout on the SSH session.
 *
 * ShouldBeUnique prevents a second job for the same deployment from
 * queueing while the first is still running.
 */
class CollectAgentTelemetryJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** Queue worker hard-kills the job after this many seconds. */
    public int $timeout = 120;

    /** A duplicate job for the same deployment won't be enqueued while this is running. */
    public int $uniqueFor = 115;

    /**
     * Telemetry is collected on a schedule, so retrying a failed cycle just
     * hammers the machine again before the next scheduled run. One attempt per
     * cycle is enough — the scheduler will retry in 2 minutes.
     */
    public int $tries = 1;

    public function __construct(public readonly int $deploymentId)
    {
    }

    public function uniqueId(): string
    {
        return (string) $this->deploymentId;
    }

    public function handle(AgentTelemetryService $service): void
    {
        $deployment = AgentDeployment::with('machine')->find($this->deploymentId);

        if ($deployment === null) {
            return;
        }

        $service->collectForDeployment($deployment);
    }
}
