<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\DealerSocket;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\DealerSocket\Actions\PullPeopleAction;
use Kanvas\Connectors\DealerSocket\Services\DealerSocketCustomerService;
use Kanvas\Guild\Customers\Models\People;
use Tests\Connectors\Traits\HasDealerSocketConfiguration;
use Tests\TestCase;

final class CustomerTest extends TestCase
{
    use HasDealerSocketConfiguration;

    public function testCreateCustomer()
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $people = People::factory()->withUserId($user->getId())
             ->withAppId($app->getId())
             ->withCompanyId($company->getId())
             ->withContacts(canUseFakeInfo: false)
             ->create();

        $region = $company->defaultRegion;

        $this->setupDealerSocketConfiguration($company, $app);

        $customerService = new DealerSocketCustomerService($app, $company);
        $response = $customerService->saveCustomer($people);

        $this->assertArrayHasKey('entityId', $response);
    }

    public function testUpdateCustomer()
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $people = People::factory()->withUserId($user->getId())
             ->withAppId($app->getId())
             ->withCompanyId($company->getId())
             ->withContacts(canUseFakeInfo: false)
             ->create();
        $region = $company->defaultRegion;

        $this->setupDealerSocketConfiguration($company, $app);

        $customerService = new DealerSocketCustomerService($app, $company);
        $response = $customerService->saveCustomer($people);

        $people->name = 'TEST - ' . now()->format('H:i:s');
        $people->firstname = 'updadte';
        $people->save();
        $response = $customerService->updateCustomer($people);

        $this->assertNotEmpty($response['success']);
    }
    /*
        public function testSearchCustomerByEmail()
        {
            $app = app(Apps::class);
            $user = auth()->user();
            $company = $user->getCurrentCompany();

            $people = People::factory()->withUserId($user->getId())
                 ->withAppId($app->getId())
                 ->withCompanyId($company->getId())
                 ->withContacts(canUseFakeInfo: false)
                 ->create();
            $region = $company->defaultRegion;

            $this->setupDealerSocketConfiguration($company, $app);

            $customerService = new DealerSocketCustomerService($app, $company);
            $response = $customerService->saveCustomer($people);


            echo "Searching for email: " . $people->getEmails()->first()->value . PHP_EOL;
            $searchResponse = $customerService->searchCustomerByEmail(
                $people->getEmails()->first()->value
            );

            print_r($searchResponse); die();

            $this->assertNotEmpty($searchResponse);
        } */

    public function testSearchCustomerByName()
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $people = People::factory()->withUserId($user->getId())
             ->withAppId($app->getId())
             ->withCompanyId($company->getId())
             ->withContacts(canUseFakeInfo: false)
             ->create();
        $region = $company->defaultRegion;

        $this->setupDealerSocketConfiguration($company, $app);

        $customerService = new DealerSocketCustomerService($app, $company);
        $response = $customerService->saveCustomer($people);

        $searchResponse = $customerService->searchCustomerByName(
            $people->firstname,
            $people->lastname,
            true
        );

        $this->assertNotEmpty($searchResponse);
        $this->assertGreaterThanOrEqual(1, count($searchResponse['customers']));
    }

    public function testSearchCustomerByPhone()
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $people = People::factory()->withUserId($user->getId())
             ->withAppId($app->getId())
             ->withCompanyId($company->getId())
             ->withContacts(canUseFakeInfo: false)
             ->create();
        $region = $company->defaultRegion;

        $this->setupDealerSocketConfiguration($company, $app);

        $customerService = new DealerSocketCustomerService($app, $company);
        $response = $customerService->saveCustomer($people);

        $searchResponse = $customerService->searchCustomerByPhone(
            $people->getPhones()->first()->value
        );

        $this->assertNotEmpty($searchResponse);
    }

    public function testPullPeopleAction(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $people = People::factory()->withUserId($user->getId())
             ->withAppId($app->getId())
             ->withCompanyId($company->getId())
             ->withContacts(canUseFakeInfo: false)
             ->create();

        $region = $company->defaultRegion;

        $this->setupDealerSocketConfiguration($company, $app);

        $customerService = new DealerSocketCustomerService($app, $company);
        $response = $customerService->saveCustomer($people);

        $pullPeopleAction = new PullPeopleAction(
            $app,
            $company,
            $user
        );

        $pulledPeople = $pullPeopleAction->execute(
            customerId: $response['entityId']
        );

        $this->assertEquals($people->firstname, $pulledPeople->firstname);

        $pulledPeople = $pullPeopleAction->execute(
            phoneNumber: $people->getPhones()->first()->value
        );

        $this->assertEquals($people->firstname, $pulledPeople->firstname);
    }
}
