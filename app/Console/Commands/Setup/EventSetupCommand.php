<?php

declare(strict_types=1);

namespace App\Console\Commands\Setup;

use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Event\Support\Setup;
use Kanvas\Users\Models\Users;

class EventSetupCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kanvas-event:setup {app_id} {user_id} {company_id}';

    /**
     * The console command description.
     *
     * @var string|null
     */
    protected $description = 'Initializes the event system';

    /**
     * Execute the console command.
     *
     */
    public function handle()
    {
        $company = Companies::getById((int) $this->argument('company_id'));
        $user = Users::getById((int) $this->argument('user_id'));
        $app = Apps::getById((int) $this->argument('app_id'));

        (new Setup(
            $app,
            $user,
            $company
        ))->run();

        $this->newLine();
        $this->info('Event setup for Company ' . $company->name . ' and App ' . $app->name . ' completed successfully');
        $this->newLine();

        return;
    }
}
