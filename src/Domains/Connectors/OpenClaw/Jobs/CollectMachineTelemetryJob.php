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
use Kanvas\Intelligence\Agents\Models\AgentMachine;

/**
 * Collect telemetry for all running deployments on a single machine.
 *
 * One job per machine instead of one per deployment. A single SSH connection
 * is opened, all containers are queried sequentially, then the connection is
 * closed — preventing concurrent SSH hammering when a machine hosts multiple
 * agents.
 *
 * Timeout accounts for the worst case: each deployment runs a 70s pipeline
 * + a 15s tools exec, so 5 deployments ≈ 425s max. We cap at 300s which
 * handles the realistic case (1-3 deployments) with headroom to spare.
 *
 * ShouldBeUnique ensures a second run for the same machine cannot queue
 * while the first is still in progress.
 */
class CollectMachineTelemetryJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 300;

    public int $uniqueFor = 290;

    /**
     * Telemetry is collected on a schedule every 2 minutes, so retrying a failed
     * cycle would just hammer the machine again before the next scheduled run.
     * One attempt per cycle is sufficient — failures are logged and the next
     * scheduler tick will try again.
     */
    public int $tries = 1;

    public function __construct(public readonly int $machineId)
    {
        $this->onQueue('agent-runtime');
    }

    public function uniqueId(): string
    {
        return 'machine-' . $this->machineId;
    }

    public function handle(AgentTelemetryService $service): void
    {
        $machine = AgentMachine::find($this->machineId);

        if ($machine === null) {
            return;
        }

        $service->collectForMachine($machine);
    }
}
