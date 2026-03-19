<?php

declare(strict_types=1);

namespace Kanvas\Connectors\OpenClaw\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Kanvas\Connectors\OpenClaw\Actions\RestartAgentContainerAction;
use Kanvas\Connectors\OpenClaw\Events\AgentDeploymentStatusChanged;
use Kanvas\Intelligence\Agents\Models\AgentDeployment;

class RestartAgentContainerJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        protected AgentDeployment $deployment,
    ) {
    }

    public function handle(): void
    {
        $previousStatus = $this->deployment->status;

        $deployment = new RestartAgentContainerAction($this->deployment)->execute();

        AgentDeploymentStatusChanged::dispatch($deployment, $previousStatus);
    }
}
