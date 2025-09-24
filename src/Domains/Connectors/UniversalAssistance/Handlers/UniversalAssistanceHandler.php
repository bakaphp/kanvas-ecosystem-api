<?php

declare(strict_types=1);

namespace Kanvas\Connectors\UniversalAssistance\Handlers;

use Kanvas\Connectors\Contracts\BaseIntegration;
use Kanvas\Connectors\UniversalAssistance\Client;
use Kanvas\Connectors\UniversalAssistance\Enums\ConfigurationEnum;
use Override;

class UniversalAssistanceHandler extends BaseIntegration
{
    #[Override]
    public function setup(): bool
    {
        // Store configuration in app settings
        $this->app->set(ConfigurationEnum::BASE_URL->value, $this->data['base_url'] ?? '');
        $this->app->set(ConfigurationEnum::USERNAME->value, $this->data['username'] ?? '');
        $this->app->set(ConfigurationEnum::PASSWORD->value, $this->data['password'] ?? '');
        $this->app->set(ConfigurationEnum::ORGANIZATION->value, $this->data['organization'] ?? '');
        $this->app->set(ConfigurationEnum::WSDL_QUOTE->value, $this->data['wsdl_quote'] ?? '');
        $this->app->set(ConfigurationEnum::WSDL_VOUCHER->value, $this->data['wsdl_voucher'] ?? '');
        $this->app->set(ConfigurationEnum::WSDL_QUERY->value, $this->data['wsdl_query'] ?? '');

        // Test the connection by attempting to create a client
        try {
            $client = new Client($this->app, $this->company);

            // Validate that we can create the SOAP clients
            // This will throw an exception if configuration is invalid
            $client->testConnection();

            return true;
        } catch (\Exception $e) {
            // Log the error if needed
            // error_log('Universal Assistance setup failed: ' . $e->getMessage());
            return false;
        }
    }
}
