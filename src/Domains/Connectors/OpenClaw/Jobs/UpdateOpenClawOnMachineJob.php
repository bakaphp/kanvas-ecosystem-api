<?php

declare(strict_types=1);

namespace Kanvas\Connectors\OpenClaw\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Kanvas\Connectors\OpenClaw\Actions\UpdateOpenClawOnMachineAction;
use Kanvas\Intelligence\AgentRuntime\Enums\DeploymentStatusEnum;
use Kanvas\Intelligence\AgentRuntime\Events\AgentDeploymentStatusChanged;
use Kanvas\Intelligence\Agents\Models\AgentDeployment;
use Kanvas\Intelligence\Agents\Models\AgentMachine;

/**
 * Scan the machine for OpenClaw installations and dispatch one
 * UpdateOpenClawForUserJob per user so each update runs independently.
 */
class UpdateOpenClawOnMachineJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 120;

    public int $tries = 1;

    public function __construct(
        protected AgentMachine $machine,
    ) {
    }

    public function handle(): void
    {
        $users = new UpdateOpenClawOnMachineAction($this->machine)->execute();

        foreach ($users as $user) {
            $deployment = AgentDeployment::where('agent_machine_id', $this->machine->id)
                ->where('system_user', $user)
                ->where('is_deleted', 0)
                ->whereNotIn('status', [
                    DeploymentStatusEnum::TERMINATED->value,
                    DeploymentStatusEnum::FAILED->value,
                ])
                ->first();

            if ($deployment) {
                $previousStatus = $deployment->status;
                $deployment->status = DeploymentStatusEnum::UPDATING->value;
                $deployment->saveOrFail();
                AgentDeploymentStatusChanged::dispatch($deployment, $previousStatus);
            }

            UpdateOpenClawForUserJob::dispatch($this->machine, $user, $deployment?->id);
        }
    }
}
