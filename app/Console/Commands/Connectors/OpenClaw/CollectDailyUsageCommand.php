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

class CollectDailyUsageCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas:openclaw-collect-usage {app_id}';

    protected $description = 'Collect daily usage data from all running OpenClaw deployments';

    public function handle(): void
    {
        /** @var Apps $app */
        $app = Apps::getById((int) $this->argument('app_id'));
        $this->overwriteAppService($app);

        $deployments = AgentDeployment::where('apps_id', $app->getId())
            ->where('status', 'running')
            ->where('is_deleted', 0)
            ->get();

        $this->info('Found ' . $deployments->count() . ' running deployments');

        foreach ($deployments as $deployment) {
            try {
                $company = Companies::getById((int) $deployment->companies_id);

                $snapshot = new CollectDeploymentUsageAction(
                    $deployment,
                    $app,
                    $company,
                )->execute();

                $this->info(
                    "Collected usage for {$deployment->container_name}"
                    . " (company: {$deployment->companies_id}): snapshot #{$snapshot->getId()}"
                );
            } catch (Throwable $e) {
                $this->error("Failed for {$deployment->container_name}: {$e->getMessage()}");
            }
        }
    }
}
