<?php

declare(strict_types=1);

namespace Kanvas\Connectors\SuperCarros\Handlers;

use Exception;
use Kanvas\Connectors\Contracts\BaseIntegration;
use Kanvas\Connectors\SuperCarros\Client;
use Kanvas\Connectors\SuperCarros\Enums\ConfigurationEnum;
use Kanvas\Exceptions\ValidationException;
use Override;

class SuperCarrosHandler extends BaseIntegration
{
    #[Override]
    public function setup(): bool
    {
        $baseUrl = $this->data['base_url'] ?? null;
        $accessKey = $this->data['access_key'] ?? null;
        $customerId = $this->data['customer_id'] ?? null;

        if (empty($baseUrl)) {
            throw new ValidationException('SuperCarros base URL is required.');
        }

        if (empty($accessKey)) {
            throw new ValidationException('SuperCarros access key is required.');
        }

        if (empty($customerId)) {
            throw new ValidationException('SuperCarros customer ID is required.');
        }

        // Store configuration in the app
        $this->app->set(ConfigurationEnum::BASE_URL->value, $baseUrl);
        $this->app->set(ConfigurationEnum::ACCESS_KEY->value, $accessKey);
        
        // Store customer ID in the company
        $this->company->set(ConfigurationEnum::CUSTOMER_ID->value, $customerId);

        try {
            // Test the connection by attempting to get vehicle list
            $client = new Client($this->app, $this->company);
            $vehicles = $client->getVehicleList();
            
            // Verify we get a valid response structure
            if (!is_array($vehicles)) {
                throw new ValidationException('Invalid response from SuperCarros API');
            }

        } catch (Exception $e) {
            if (str_contains($e->getMessage(), 'Unauthorized') || str_contains($e->getMessage(), 'Invalid')) {
                throw new ValidationException('Invalid SuperCarros API credentials.');
            }

            throw new ValidationException('SuperCarros connection failed: ' . $e->getMessage());
        }

        return true;
    }
}