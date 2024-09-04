<?php

declare(strict_types=1);

namespace App\Console\Commands\Setup;

use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Support\Setup;
use Kanvas\Users\Models\Users;

class GuildSetupCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kanvas-guild:setup {app_id} {user_id} {company_id}';

    /**
     * The console command description.
     *
     * @var string|null
     */
    protected $description = 'Initializes the CRM system';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $app = Apps::getById((int) $this->argument('app_id'));
        $company = Companies::getById((int) $this->argument('company_id'));
        $user = Users::getById((int) $this->argument('user_id'));

        //todo: add setup class
        (new Setup(
            $app,
            $user,
            $company
        ))->run();

        $this->newLine();
        $this->info('Guild setup for Company ' . $company->name . ' completed successful');
        $this->newLine();

        return;
    }
}
