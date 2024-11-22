<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\NetSuite;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\NetSuite\Actions\SyncCompanyWithNetSuiteAction;
use Kanvas\Connectors\NetSuite\Actions\SyncNetSuiteCustomerItemsListAction;
use Kanvas\Connectors\NetSuite\Actions\SyncNetSuiteCustomerWithCompanyAction;
use Kanvas\Connectors\NetSuite\Actions\SyncNetSuiteCustomerWithPeopleAction;
use Kanvas\Connectors\NetSuite\Client;
use Kanvas\Connectors\NetSuite\DataTransferObject\NetSuite;
use Kanvas\Connectors\NetSuite\Enums\CustomFieldEnum;
use Kanvas\Connectors\NetSuite\Services\NetSuiteCustomerService;
use Kanvas\Connectors\NetSuite\Services\NetSuiteServices;
use Kanvas\Users\Actions\AssignCompanyAction;
use Tests\TestCase;

final class CustomerTest extends TestCase
{
    public function testSetup()
    {
        $app = app(Apps::class);
        $company = Companies::first();
        $data = new NetSuite(
            app: $app,
            company: $company,
            account: getenv('NET_SUITE_ACCOUNT'),
            consumerKey: getenv('NET_SUITE_CONSUMER_KEY'),
            consumerSecret: getenv('NET_SUITE_CONSUMER_SECRET'),
            token: getenv('NET_SUITE_TOKEN'),
            tokenSecret: getenv('NET_SUITE_TOKEN_SECRET')
        );

        $result = NetSuiteServices::setup($data);

        $this->assertTrue($result);
    }

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

    public function testSyncNetSuiteCustomerItemsList()
    {
        $user = auth()->user();
        $app = app(Apps::class);
        $company = Companies::first();

        $companyIdToSync = getenv('NET_SUITE_CUSTOMER_ID');
        $syncCompany = new SyncNetSuiteCustomerWithCompanyAction($app, $company);
        $buyerCompany = $syncCompany->execute($companyIdToSync);

        $assignCompanyAction = new AssignCompanyAction(
            user: $company->user,
            branch: $company->defaultBranch,
            app: $app
        );
        $assignCompanyAction->execute();

        $company->associateUser($company->user, true, $company->defaultBranch);

        $syncCustomerItemsList = new SyncNetSuiteCustomerItemsListAction($app, $company, $buyerCompany);
        $result = $syncCustomerItemsList->execute();

        $this->assertIsArray($result);
    }

    public function testGetCustomerInvoiceByNumber()
    {
        $company = Companies::first();
        $app = app(Apps::class);

        $netSuiteService = new NetSuiteCustomerService($app, $company);
        $invoiceNumber = getenv('NET_SUITE_INVOICE_NUMBER');
        $customerInvoices = $netSuiteService->getInvoiceByNumber($invoiceNumber);

        $this->assertIsArray($customerInvoices);
    }
}
