<?php

declare(strict_types=1);

namespace Kanvas\Connectors\AgentRuntime\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Kanvas\Connectors\AgentRuntime\Actions\UpdateAgentRuntimeOnMachineAction;
use Kanvas\Intelligence\AgentRuntime\Enums\DeploymentStatusEnum;
use Kanvas\Intelligence\AgentRuntime\Events\AgentDeploymentStatusChanged;
use Kanvas\Intelligence\Agents\Models\AgentDeployment;
use Kanvas\Intelligence\Agents\Models\AgentMachine;

/**
 * Scan the machine for AgentRuntime installations and dispatch one
 * UpdateAgentRuntimeForUserJob per user so each update runs independently.
 */
class UpdateAgentRuntimeOnMachineJob implements ShouldQueue
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
        $users = new UpdateAgentRuntimeOnMachineAction($this->machine)->execute();

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

            UpdateAgentRuntimeForUserJob::dispatch($this->machine, $user, $deployment?->id);
        }
    }
}
