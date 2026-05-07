<?php

declare(strict_types=1);

namespace App\Console\Commands\Setup;

use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Souk\Orders\Models\OrderTypes;
use Kanvas\Users\Models\UserCompanyApps;
use Throwable;

class KanvasCommerceDefaultUpdate extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kanvas:commerce-default-update {app_uuid}';

    /**
     * The console command description.
     *
     * @var string|null
     */
    protected $description = 'Set defaults entities value for commerce companies';

    /**
     * Execute the console command.
     *
     */
    public function handle()
    {
        $appUid = $this->argument('app_uuid');
        $app = Apps::getByUuid($appUid);

        $associatedApps = UserCompanyApps::where('apps_id', $app->getId())->get();

        foreach ($associatedApps as $company) {
            $companyData = $company->company;
            if (! $companyData) {
                continue;
            }
            $defaultOrderType = OrderTypes::getDefault($companyData);

            $this->info("Checking company {$companyData->getId()} \n");

            if (! $defaultOrderType) {
                $this->info("Working company {$companyData->getId()} default order type \n");

                try {
                    $defaultOrderType = OrderTypes::firstOrCreate([
                        'apps_id' => $app->getId(),
                        'companies_id' => $companyData->getId(),
                        'name' => 'Default',
                    ], [
                        'is_default' => true,
                    ]);
                } catch (Throwable $e) {
                    $this->error('Error creating default order type for : ' . $companyData->getId() . ' ' . $e->getMessage());
                }
            }
        }

        return;
    }
}
