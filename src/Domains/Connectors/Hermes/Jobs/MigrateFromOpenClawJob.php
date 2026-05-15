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
use Kanvas\Connectors\Hermes\Actions\MigrateFromOpenClawAction;
use Kanvas\Intelligence\AgentRuntime\Events\AgentDeploymentStatusChanged;
use Kanvas\Intelligence\Agents\Models\AgentDeployment;
use Kanvas\Intelligence\Agents\Models\AgentMachine;
use Throwable;

class MigrateFromOpenClawJob implements ShouldQueue
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
            $destDeployment = new MigrateFromOpenClawAction(
                $sourceDeployment,
                $this->destinationMachine,
                $this->app,
                $this->company,
                $this->sourcePath,
                $this->destinationPath,
            )->execute();

            AgentDeploymentStatusChanged::dispatch($destDeployment, 'provisioning');
        } catch (Throwable $e) {
            report($e);
            AgentDeploymentStatusChanged::dispatch($sourceDeployment->fresh(), 'provisioning');

            throw $e;
        }
    }

    public function failed(Throwable $e): void
    {
        $sourceDeployment = AgentDeployment::find($this->sourceDeployment->id);

        if (! $sourceDeployment) {
            return;
        }

        AgentDeploymentStatusChanged::dispatch($sourceDeployment, 'provisioning');
    }
}
