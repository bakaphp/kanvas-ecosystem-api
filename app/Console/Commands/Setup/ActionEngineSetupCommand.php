<?php

declare(strict_types=1);

namespace App\Console\Commands\Setup;

use Illuminate\Console\Command;
use Kanvas\ActionEngine\Support\Setup;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Users\Models\Users;

class ActionEngineSetupCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kanvas-action-engine:setup {app_id} {user_id} {company_id} {actions?} {--from-company=}';

    /**
     * The console command description.
     *
     * @var string|null
     */
    protected $description = 'Initializes the Action Engine system';

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        /** @var Apps $app */
        $app = Apps::getById((int) $this->argument('app_id'));
        $company = Companies::getById((int) $this->argument('company_id'));
        $user = Users::getById((int) $this->argument('user_id'));

        $actionsKey = $this->argument('actions');
        $actions = is_string($actionsKey) ? $app->get($actionsKey, []) : [];

        $fromCompanyId = $this->option('from-company');
        $fromCompany = $fromCompanyId !== null
            ? Companies::getById((int) $fromCompanyId)
            : null;

        new Setup(
            $app,
            $user,
            $company,
            $actions,
            $fromCompany
        )->run();

        $this->newLine();
        $this->info('Action Engine setup for Company ' . $company->name . ' completed successfully');
        $this->newLine();

        return;
    }
}
