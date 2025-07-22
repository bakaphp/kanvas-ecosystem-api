<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\NetSuite;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Companies\Repositories\CompaniesRepository;
use Kanvas\Connectors\NetSuite\Actions\SyncNetSuiteProductsAction;

class NetSuiteDownloadProductsCommand extends Command
{
    use KanvasJobsTrait;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kanvas:netsuite-download-products {app_id} {company_id} {buyer_company_id}';

    /**
     * The console command description.
     *
     * @var string|null
     */
    protected $description = 'Download products from NetSuite to this company';

    /**
     * Execute the console command.
     *
     */
    public function handle()
    {
        $app = Apps::getById((int) $this->argument('app_id'));
        $this->overwriteAppService($app);
        $company = Companies::getById((int) $this->argument('company_id'));
        $buyerCompanyId = $this->argument('buyer_company_id');

        $this->info("Downloading products from company {$buyerCompanyId} to company {$company->getId()} \n");

        $buyerCompany = CompaniesRepository::getByUuid($buyerCompanyId);

        $syncNetSuiteCustomerWithCompany = new SyncNetSuiteProductsAction($app, $company, $buyerCompany);

        $syncNetSuiteCustomerWithCompany->execute();

        $this->info("All products from company {$buyerCompany->name} downloaded successfully \n");

        return;
    }
}
