<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\DealerSocket;

use Kanvas\Apps\Models\Apps;
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
}
