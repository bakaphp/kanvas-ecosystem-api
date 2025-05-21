<?php

declare(strict_types=1);

namespace Kanvas\Connectors\DriveCentric\Services;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\DriveCentric\Client;
use Kanvas\Connectors\DriveCentric\Enums\ConfigurationEnum;

class CustomerService
{
    public Client $client;
    public function __construct(
        protected Companies $company,
        protected Apps $app
    ) {
        $this->client = new Client($this->app, $this->company);
    }

    public function getCustomers(int $offset = 0, string $start = 'today', ?string $endDate = null): array
    {
        $endDate = $endDate ?? date('Y-m-d');
        $storeId = $this->app->get(ConfigurationEnum::STORE_ID->value);
        $response = $this->client->getClient()->get("{+endpoint}/api/Stores/{$storeId}/Customers/List", [
            'Offset' => $offset,
            'Start' => $start,
            'End' => $endDate,
        ]);

        return $response->json();
    }

    public function getCustomerById(string $customerId): array
    {
        $storeId = $this->app->get(ConfigurationEnum::STORE_ID->value);
        $response = $this->client->getClient()->get("{+endpoint}/api/Stores/{$storeId}/Customers/{$customerId}");
        $customer = $response->json('customerInfo');

        return $customer;
    }

    public function getCustomerByEmail(string $email): array
    {
        $storeId = $this->app->get(ConfigurationEnum::STORE_ID->value);
        $response = $this->client->getClient()->get("{+endpoint}/api/stores/{$storeId}/customers", [
            'email' => $email,
        ]);
        $customer = $response->json('customers.0');
        return $customer;
    }

    public function getCustomerByPhone(string $phone): array
    {
        $storeId = $this->app->get(ConfigurationEnum::STORE_ID->value);
        $response = $this->client->getClient()->get("{+endpoint}/api/stores/{$storeId}/customers", [
            'phone' => $phone,
        ]);
        $customer = $response->json('customers.0');
        return $customer;
    }
}
