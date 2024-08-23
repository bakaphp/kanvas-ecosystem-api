<?php
declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Kanvas\Companies\Repositories\CompaniesRepository;
use Kanvas\Apps\Repositories\AppsRepository;
use Kanvas\Apps\Models\Apps;
use Kanvas\Dashboard\Actions\SetDefaultDashboardFieldAction;

class KanvasDashboardDefaultFieldCommand extends Command
{
    protected $signature = "kanvas:dashboard-default-field {app_id}";

    protected $description = "Set default dashboard field";

    public function handle(): void
    {
        $this->info("Setting default dashboard field...");
        $app = Apps::findOrFail($this->argument('app_id'));
        foreach($app->companies as $company) {
            (new SetDefaultDashboardFieldAction($company))->execute();
        }
    }
}
