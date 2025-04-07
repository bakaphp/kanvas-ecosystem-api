<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\NetSuite;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\NetSuite\Actions\SyncNetSuiteCustomerItemsListAction;
use Kanvas\Connectors\NetSuite\Actions\SyncNetSuiteCustomerWithCompanyAction;

class NetSuiteDownloadCustomerCommand extends Command
{
    use KanvasJobsTrait;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kanvas:netsuite-download-customer {app_id} {company_id} {net_suite_customer_id}';

    /**
     * The console command description.
     *
     * @var string|null
     */
    protected $description = 'Download customer from NetSuite to this company';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $app = Apps::getById((int) $this->argument('app_id'));
        $this->overwriteAppService($app);
        $company = Companies::getById((int) $this->argument('company_id'));
        $netSuiteCustomerId = $this->argument('net_suite_customer_id');

        $this->info("Downloading customer {$netSuiteCustomerId} to company {$company->getId()} \n");

        $syncCompany = new SyncNetSuiteCustomerWithCompanyAction($app, $company);
        $newCompany = $syncCompany->execute($netSuiteCustomerId);

        $syncNetSuiteCustomerWithCompany = new SyncNetSuiteCustomerItemsListAction($app, $company, $newCompany);
        $syncNetSuiteCustomerWithCompany->execute();

        $this->info("Customer {$newCompany->name} downloaded successfully \n");

        return;
    }
}
