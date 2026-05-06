<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Hermes\Jobs;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Kanvas\Connectors\AgentRuntime\Events\AgentDeploymentStatusChanged;
use Kanvas\Connectors\Hermes\Actions\LaunchAgentOnMachineAction;
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

    public function __construct(
        protected Agent $agent,
        protected AgentMachine $machine,
        protected AppInterface $app,
        protected CompanyInterface $company,
        protected AgentDeployment $deployment,
    ) {
        $this->onQueue('hermes');
    }

    public function handle(): void
    {
        /** @var AgentDeployment $deployment */
        $deployment = AgentDeployment::findOrFail($this->deployment->id);

        try {
            $deployment = new LaunchAgentOnMachineAction(
                $this->agent,
                $this->machine,
                $this->app,
                $this->company,
                $deployment,
            )->execute();

            AgentDeploymentStatusChanged::dispatch($deployment, 'provisioning');
        } catch (Throwable $e) {
            report($e);
            AgentDeploymentStatusChanged::dispatch($deployment, 'provisioning');

            throw $e;
        }
    }
}
