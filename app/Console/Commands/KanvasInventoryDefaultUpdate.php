<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Baka\Support\Str;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Currencies\Models\Currencies;
use Kanvas\Inventory\Regions\Models\Regions;
use Kanvas\Inventory\Status\Models\Status;
use Kanvas\Inventory\Warehouses\Models\Warehouses;
use Kanvas\Users\Models\UserCompanyApps;
use Throwable;

class KanvasInventoryDefaultUpdate extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kanvas:inventory-default-update {app_uuid}';

    /**
     * The console command description.
     *
     * @var string|null
     */
    protected $description = 'Set defaults entities value for inventory companies';

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
            $defaultRegion = Regions::getDefault($companyData);

            $this->info("Checking company {$companyData->getId()} \n");
            $this->info("Checking company {$companyData->getId()} default status \n");

            if (!$defaultRegion) {
                $this->info("Working company {$companyData->getId()} default region \n");
                try {
                    $defaultRegion = Regions::firstOrCreate([
                        'apps_id' => $app->getId(),
                        'companies_id' => $companyData->getId(),
                        'slug' => Str::slug("Default"),
                    ], [
                        'name' => "Default",
                        'is_default' => true,
                        'currency_id' => Currencies::where('code', 'USD')->firstOrFail()->getId(),
                        'users_id' => $companyData->users_id,
                        'short_slug' => Str::slug("Default")
                    ]);
                } catch (Throwable $e) {
                    $this->error('Error creating default region for : ' . $companyData->getId() . ' ' . $e->getMessage());
                }
            }

            if (!$defaultWarehouses) {
                $this->info("Working company {$companyData->getId()} default warehouse \n");
                try {
                    $defaultWarehouses = Warehouses::firstOrCreate([
                        'name' => "Default",
                        'companies_id' => $companyData->getId(),
                        'apps_id' => $app->getId(),
                        'regions_id' => $defaultRegion->getId(),
                    ], [
                        'is_default' => true,
                        'users_id' => $companyData->users_id,
                        'is_published' => true
                    ]);
                } catch (Throwable $e) {
                    dd($e);
                    $this->error('Error creating default warehouse for : ' . $companyData->getId() . ' ' . $e->getMessage());
                }
            }

            if (!$defaultStatus) {
                $this->info("Working company {$companyData->getId()} default status \n");
                try {
                    $defaultStatus = Status::firstOrCreate([
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
