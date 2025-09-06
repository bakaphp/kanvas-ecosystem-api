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
    protected $signature = 'kanvas-action-engine:setup {app_id} {user_id} {company_id} {actions}';

    /**
     * The console command description.
     *
     * @var string|null
     */
    protected $description = 'Initializes the Action Engine system';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $app = Apps::getById((int) $this->argument('app_id'));
        $company = Companies::getById((int) $this->argument('company_id'));
        $user = Users::getById((int) $this->argument('user_id'));

        $actions = $app->get($this->argument('actions'), []);

        if (empty($actions)) {
            $this->error('Actions array cannot be empty');

            return;
        }

        //todo: add setup class
        (new Setup(
            $app,
            $user,
            $company,
            $actions
        ))->run();

        $this->newLine();
        $this->info('Action Engine setup for Company ' . $company->name . ' completed successfully');
        $this->newLine();

        return;
    }
}
