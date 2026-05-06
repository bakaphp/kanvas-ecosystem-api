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
use Kanvas\Connectors\OpenClaw\Actions\MigrateAgentWorkspaceAction;
use Kanvas\Connectors\AgentRuntime\Events\AgentDeploymentStatusChanged;
use Kanvas\Intelligence\Agents\Models\AgentDeployment;
use Kanvas\Intelligence\Agents\Models\AgentMachine;
use Throwable;

class MigrateAgentWorkspaceJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 1800;

    public int $tries = 1;

    public function __construct(
        protected AgentDeployment $sourceDeployment,
        protected AgentMachine $destinationMachine,
        protected AppInterface $app,
        protected CompanyInterface $company,
        protected ?string $sourcePath = null,
        protected ?string $destinationPath = null,
    ) {
    }

    public function handle(): void
    {
        /** @var AgentDeployment $sourceDeployment */
        $sourceDeployment = AgentDeployment::findOrFail($this->sourceDeployment->id);

        try {
            $destDeployment = new MigrateAgentWorkspaceAction(
                $sourceDeployment,
                $this->destinationMachine,
                $this->app,
                $this->company,
                $this->sourcePath,
                $this->destinationPath,
            )->execute();

            AgentDeploymentStatusChanged::dispatch($destDeployment, 'provisioning');
        } catch (Throwable $e) {
            AgentDeploymentStatusChanged::dispatch($sourceDeployment->fresh(), 'provisioning');

            throw $e;
        }
    }
}
