<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\NetSuite;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\NetSuite\Actions\SyncCompanyWithNetSuiteAction;
use Kanvas\Connectors\NetSuite\Actions\SyncNetSuiteCustomerWithCompanyAction;
use Kanvas\Connectors\NetSuite\Actions\SyncNetSuiteCustomerWithPeopleAction;
use Kanvas\Connectors\NetSuite\Client;
use Kanvas\Connectors\NetSuite\Enums\CustomFieldEnum;
use Kanvas\Connectors\NetSuite\Services\NetSuiteCustomerService;
use Tests\TestCase;

final class CustomerTest extends TestCase
{
    public function testSynCompanyWithNetSuite()
    {
        /*  $company = Companies::first();
         $app = app(Apps::class);

         //$netSuiteService = new Client($app, $company);
         //@todo use netsuite sandbox

         $syncCompany = new SyncCompanyWithNetSuiteAction($app, $company);
         $result = $syncCompany->execute();

         $this->assertTrue($result->get(CustomFieldEnum::NET_SUITE_CUSTOMER_ID->value) > 0); */
    }

    public function testGetCustomerInfo()
    {
        $company = Companies::first();
        $app = app(Apps::class);

        $netSuiteService = new NetSuiteCustomerService($app, $company);
        $netSuiteId = getenv('NET_SUITE_CUSTOMER_ID');
        //$customerInfo = $netSuiteService->getCustomerById($company->get(CustomFieldEnum::NET_SUITE_CUSTOMER_ID->value));
        $customerInfo = $netSuiteService->getCustomerById($netSuiteId);

        $this->assertEquals($netSuiteId, $customerInfo->internalId);
    }

    public function testSyncNetSuiteCompanyWithKanvasCompany()
    {
        $company = Companies::first();
        $app = app(Apps::class);
        $companyIdToSync = getenv('NET_SUITE_CUSTOMER_ID');
        $syncCompany = new SyncNetSuiteCustomerWithCompanyAction($app, $company);
        $result = $syncCompany->execute($companyIdToSync);

        $this->assertEquals($result->get(CustomFieldEnum::NET_SUITE_CUSTOMER_ID->value), $companyIdToSync);
    }

    public function testSyncNetSuiteCustomerWithPeople()
    {
        $company = Companies::first();
        $app = app(Apps::class);
        $customerId = getenv('NET_SUITE_CUSTOMER_ID');

        $syncCustomer = new SyncNetSuiteCustomerWithPeopleAction($app, $company);
        $result = $syncCustomer->execute($customerId);

        $this->assertEquals($result->get(CustomFieldEnum::NET_SUITE_CUSTOMER_ID->value), $customerId);
    }
}
