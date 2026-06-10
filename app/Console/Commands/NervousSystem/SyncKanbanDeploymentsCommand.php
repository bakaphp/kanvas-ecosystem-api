<?php

declare(strict_types=1);

namespace App\Console\Commands\NervousSystem;

use Illuminate\Console\Command;
use Kanvas\Intelligence\Agents\Enums\AgentProviderEnum;
use Kanvas\Intelligence\Agents\Models\AgentDeployment;
use Kanvas\NervousSystem\Plan\Jobs\SyncDeploymentKanbanJob;

/**
 * Fans out a kanban sync job per running Hermes deployment. Scheduled in Kernel; each job is
 * watermark-free (status-diff) so over-running is harmless.
 */
class SyncKanbanDeploymentsCommand extends Command
{
    protected $signature = 'kanvas:nervous-system:sync-kanban';

    protected $description = 'Mirror running Hermes agent kanban boards into NervousSystem plans/tasks';

    public function handle(): int
    {
        $deployments = AgentDeployment::query()
            ->where('status', 'running')
            ->where('provider', AgentProviderEnum::HERMES->value)
            ->where('is_deleted', 0)
            ->get();

        foreach ($deployments as $deployment) {
            SyncDeploymentKanbanJob::dispatch($deployment);
        }

        $this->info("Dispatched kanban sync for {$deployments->count()} deployment(s).");

        return self::SUCCESS;
    }
}
