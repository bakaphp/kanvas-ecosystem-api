<?php

declare(strict_types=1);

namespace Kanvas\Connectors\OpenClaw\Jobs;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Kanvas\Connectors\OpenClaw\Actions\LaunchAgentOnMachineAction;
use Kanvas\Connectors\OpenClaw\Events\AgentDeploymentStatusChanged;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentDeployment;
use Kanvas\Intelligence\Agents\Models\AgentMachine;
use Throwable;

class LaunchAgentJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    protected ?int $deploymentId = null;

    public function __construct(
        protected Agent $agent,
        protected AgentMachine $machine,
        protected AppInterface $app,
        protected CompanyInterface $company,
        ?AgentDeployment $deployment = null,
    ) {
        $this->deploymentId = $deployment?->getId();
    }

    public function handle(): void
    {
        $existingDeployment = $this->deploymentId
            ? AgentDeployment::find($this->deploymentId)
            : null;

        try {
            $deployment = new LaunchAgentOnMachineAction(
                $this->agent,
                $this->machine,
                $this->app,
                $this->company,
                $existingDeployment,
            )->execute();

            AgentDeploymentStatusChanged::dispatch($deployment, 'provisioning');
        } catch (Throwable $e) {
            $deployment = $existingDeployment ?? $this->agent->activeDeployment;

            if ($deployment !== null) {
                AgentDeploymentStatusChanged::dispatch($deployment, 'provisioning');
            }

            throw $e;
        }
    }
}
