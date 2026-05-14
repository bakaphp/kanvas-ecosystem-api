<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\OpenClaw;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\OpenClaw\Actions\CollectDeploymentUsageAction;
use Kanvas\Intelligence\Agents\Models\AgentDeployment;
use Throwable;

/**
 * Collect usage data from all running Docker-deployed OpenClaw agents.
 *
 * Finds all AgentDeployments with status=running for the given app,
 * SSHs into each container to run `openclaw status --usage --json`,
 * and stores the result in AgentUsageSnapshot.
 *
 * Usage:
 *   php artisan kanvas:openclaw-collect-deployment-usage {app_id}
 *
 * Intended to run on a cron (e.g. daily or hourly).
 */
class CollectDeploymentUsageCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas:openclaw-collect-deployment-usage {app_id}';

    protected $description = 'Collect usage data from all running OpenClaw Docker deployments';

    public function handle(): void
    {
        /** @var Apps $app */
        $app = Apps::getById((int) $this->argument('app_id'));
        $this->overwriteAppService($app);

        $deployments = AgentDeployment::where('apps_id', $app->getId())
            ->where('status', 'running')
            ->where('is_deleted', 0)
            ->get();

        $this->info("Found {$deployments->count()} running deployments for app {$app->name}");

        foreach ($deployments as $deployment) {
            /** @var AgentDeployment $deployment */
            /** @var Companies $company */
            $company = Companies::getById($deployment->companies_id);

            try {
                $snapshot = new CollectDeploymentUsageAction(
                    $deployment,
                    $app,
                    $company,
                )->execute();

                $this->info(
                    "Collected usage for deployment #{$deployment->getId()}"
                    . " (container: {$deployment->container_name})"
                    . " → snapshot #{$snapshot->getId()}"
                );
            } catch (Throwable $e) {
                $this->error(
                    "Failed for deployment #{$deployment->getId()}"
                    . " (container: {$deployment->container_name})"
                    . ": {$e->getMessage()}"
                );
            }
        }
    }
}
