<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\NetSuite;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\NetSuite\Actions\SyncCompanyWithNetSuiteAction;
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
       /*  $company = Companies::first();
        $app = app(Apps::class);
        //$customerId = '123'; // Replace with a valid customer ID

        $netSuiteService = new NetSuiteCustomerService($app, $company);
        $netSuiteId = $company->get(CustomFieldEnum::NET_SUITE_CUSTOMER_ID->value);
        $customerInfo = $netSuiteService->getCustomerInfo($company->get(CustomFieldEnum::NET_SUITE_CUSTOMER_ID->value));

        $this->assertEquals($netSuiteId, $customerInfo->internalId); */
    }
}
