<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Hermes\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Kanvas\Connectors\Hermes\Actions\RestartAgentContainerAction;
use Kanvas\Intelligence\AgentRuntime\Events\AgentDeploymentStatusChanged;
use Kanvas\Intelligence\Agents\Models\AgentDeployment;
use Throwable;

class RestartAgentContainerJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        protected AgentDeployment $deployment,
    ) {
        $this->onQueue('agent-runtime');
    }

    public function handle(): void
    {
        $previousStatus = $this->deployment->status;

        try {
            $deployment = new RestartAgentContainerAction($this->deployment)->execute();

            AgentDeploymentStatusChanged::dispatch($deployment, $previousStatus);
        } catch (Throwable $e) {
            report($e);

            throw $e;
        }
    }
}
