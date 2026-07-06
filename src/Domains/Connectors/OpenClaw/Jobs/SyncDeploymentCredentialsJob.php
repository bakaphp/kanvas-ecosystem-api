<?php

declare(strict_types=1);

namespace Kanvas\Connectors\OpenClaw\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Kanvas\Connectors\OpenClaw\Actions\SyncDeploymentCredentialsAction;
use Kanvas\Intelligence\Agents\Models\AgentDeployment;
use Throwable;

class SyncDeploymentCredentialsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public function __construct(
        protected AgentDeployment $deployment,
    ) {
        $this->onQueue('agent-runtime');
    }

    public function handle(): void
    {
        try {
            new SyncDeploymentCredentialsAction($this->deployment)->execute();
        } catch (Throwable $e) {
            report($e);

            throw $e;
        }
    }
}
