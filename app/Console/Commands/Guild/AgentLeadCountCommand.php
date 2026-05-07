<?php

declare(strict_types=1);

namespace App\Console\Commands\Guild;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Agents\Models\Agent;

class AgentLeadCountCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas-guild:agent-lead-count {apps_id?}';
    protected $description = 'Calculate total leads count for all active agents';

    public function handle(): void
    {
        $appsId = $this->argument('apps_id');

        $apps = $appsId
            ? Apps::where('id', (int) $appsId)->get()
            : Apps::all();

        foreach ($apps as $app) {
            $this->overwriteAppService($app);
            $this->info("Processing app: {$app->name} (ID: {$app->id})");

            $updated = DB::connection('crm')->statement("
                UPDATE agents a
                SET a.total_leads = (
                    SELECT COUNT(*)
                    FROM leads l
                    WHERE l.users_id = a.users_id
                      AND l.companies_id = a.companies_id
                      AND l.apps_id = ?
                      AND l.is_deleted = 0
                )
                WHERE a.status_id = 1
                  AND a.is_deleted = 0
            ", [$app->getId()]);

            $count = Agent::where('status_id', 1)
                ->where('is_deleted', 0)
                ->count();

            $this->info("Updated total_leads for {$count} active agents in app {$app->name}");
        }

        $this->info('Agent lead count calculation completed.');
    }
}
