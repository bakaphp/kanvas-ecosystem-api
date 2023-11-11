<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Baka\Support\Str;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Inventory\Status\Models\Status;
use Kanvas\Inventory\Warehouses\Models\Warehouses;
use Kanvas\Users\Models\UserCompanyApps;
use Throwable;

class KanvasInventoryStatusUpdate extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kanvas:inventory-status-update {app_uuid}';

    /**
     * The console command description.
     *
     * @var string|null
     */
    protected $description = 'Set defaults status value for inventory companies';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $appUid = $this->argument('app_uuid');
        $app = Apps::getByUuid($appUid);

        $associatedApps = UserCompanyApps::where('apps_id', $app->getId())->get();

        foreach ($associatedApps as $company) {
            $companyData = $company->company;
            if (!$companyData) {
                continue;
            }
            $defaultWarehouses = Warehouses::getDefault($companyData);
            $defaultStatus = Status::getDefault($companyData);
            $this->info("Checking company {$companyData->getId()} \n");
            if ($defaultWarehouses && !$defaultStatus) {
                $this->info("Working company {$companyData->getId()} \n");
                try {
                    Status::firstOrCreate([
                        'apps_id' => $app->getId(),
                        'companies_id' => $companyData->getId(),
                        'slug' => Str::slug("Default"),
                    ], [
                        'name' => "Default",
                        'is_default' => true,
                    ]);
                } catch (Throwable $e) {
                    $this->error('Error creating default status for : ' . $companyData->getId() . ' ' . $e->getMessage());
                }
            }
        }

        return;
    }
}
