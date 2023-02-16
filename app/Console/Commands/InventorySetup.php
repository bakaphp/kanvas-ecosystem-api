<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Inventory\Support\Setup;
use Kanvas\Users\Models\Users;

class InventorySetup extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'inventory:setup {appId} {userId} {companyId}';

    /**
     * The console command description.
     *
     * @var string|null
     */
    protected $description = 'Initializes the inventory system';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $app = Apps::getById((int) $this->argument('appId'));
        $company = Companies::getById((int) $this->argument('companyId'));
        $user = Users::getById((int) $this->argument('userId'));

        (new Setup(
            $app,
            $user,
            $company
        ))->run();

        $this->newLine();
        $this->info('Company ' . $company->name . ' has been setup with inventory');
        $this->newLine();

        return;
    }
}
