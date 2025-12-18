<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\DealerSocket;

use Kanvas\Connectors\DealerSocket\Services\DealerSocketCustomerService;
use Kanvas\Guild\Customers\Models\People;
use Tests\Connectors\Traits\HasDealerSocketConfiguration;
use Tests\TestCase;

final class CustomerTest extends TestCase
{
    use HasDealerSocketConfiguration;

    public function testCreateCustomer()
    {
        $people = People::first();
        $company = $people->company;
        $app = $people->app;
        $region = $company->defaultRegion;

        $this->setupDealerSocketConfiguration($company, $app);

        $customerService = new DealerSocketCustomerService($app, $company);
        $response = $customerService->saveCustomer($people);

        $this->assertArrayHasKey('entityId', $response);
    }

    public function testUpdateCustomer()
    {
        $people = People::first();
        $company = $people->company;
        $app = $people->app;
        $region = $company->defaultRegion;

        $this->setupDealerSocketConfiguration($company, $app);

        $customerService = new DealerSocketCustomerService($app, $company);

        $people->name = 'TEST - ' . now()->format('H:i:s');
        $people->firstname = 'updadte';
        $people->save();
        $response = $customerService->updateCustomer($people);

        $this->assertNotEmpty($response['success']);
    }
}
