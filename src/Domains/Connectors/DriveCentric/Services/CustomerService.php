<?php

declare(strict_types=1);

namespace Kanvas\Connectors\DriveCentric\Services;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\DriveCentric\Client;
use Kanvas\Connectors\DriveCentric\Enums\ConfigurationEnum;

class CustomerService
{
    public function __construct(
        protected Companies $company,
        protected Apps $app
    ) {
    }

    public function getCustomers(int $offset = 0, string $start = 'today', ?string $endDate = null): array
    {
        $endDate = $endDate ?? date('Y-m-d');
        $client = new Client($this->app);
        $storeId = $this->app->get(ConfigurationEnum::STORE_ID->value);
        $response = $client->getClient()->get("{+endpoint}/api/Stores/{$storeId}/Customers/List", [
            'Offset' => $offset,
            'Start' => $start,
            'End' => $endDate,
        ]);

        return $response->json();
    }

    public function getCustomer(string $customerId): array
    {
        $client = new Client($this->app);
        $storeId = $this->app->get(ConfigurationEnum::STORE_ID->value);
        $response = $client->getClient()->get("{+endpoint}/api/Stores/{$storeId}/Customers/{$customerId}");
        $customer = $response->json('customerInfo');

        return $customer;
    }
}
