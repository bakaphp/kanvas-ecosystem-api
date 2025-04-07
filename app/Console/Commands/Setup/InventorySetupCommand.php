<?php

declare(strict_types=1);

namespace App\Console\Commands\Setup;

use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Inventory\Support\Setup;
use Kanvas\Users\Models\Users;

class InventorySetupCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kanvas-inventory:setup {app_id} {user_id} {company_id}';

    /**
     * The console command description.
     *
     * @var string|null
     */
    protected $description = 'Initializes the inventory system';

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

        (new Setup(
            $app,
            $user,
            $company
        ))->run();

        $this->newLine();
        $this->info('Inventory setup for Company ' . $company->name . ' completed successful');
        $this->newLine();

        return;
    }
}
