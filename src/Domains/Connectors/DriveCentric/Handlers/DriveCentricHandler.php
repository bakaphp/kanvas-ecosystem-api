<?php

declare(strict_types=1);

namespace Kanvas\Connectors\DriveCentric\Handlers;

use Kanvas\Connectors\Contracts\BaseIntegration;
use Kanvas\Connectors\DriveCentric\Client;
use Kanvas\Connectors\DriveCentric\Enums\ConfigurationEnum;
use Override;

class DriveCentricHandler extends BaseIntegration
{
    #[Override]
    public function setup(): bool
    {
        $this->app->set(ConfigurationEnum::BASE_URL->value, $this->data['base_url']);
        $this->app->set(ConfigurationEnum::API_KEY->value, $this->data['api_key']);
        $this->app->set(ConfigurationEnum::API_SECRET_KEY->value, $this->data['api_secret_key']);
        $this->company->set(ConfigurationEnum::STORE_ID->value, $this->data['store_id']);

        // Optional configurations
        if (isset($this->data['default_source_type'])) {
            $this->app->set(ConfigurationEnum::DEFAULT_SOURCE_TYPE->value, $this->data['default_source_type']);
        }
        if (isset($this->data['default_source_description'])) {
            $this->app->set(ConfigurationEnum::DEFAULT_SOURCE_DESCRIPTION->value, $this->data['default_source_description']);
        }

        // Validate connection
        $client = new Client($this->app, $this->company);
        $client->getClient();

        return true;
    }

    /**
     * Get the integration status.
     */
    public function getStatus(): array
    {
        return [
            'connected' => $this->isConfigured(),
            'base_url' => $this->app->get(ConfigurationEnum::BASE_URL->value),
            'store_id' => $this->company->get(ConfigurationEnum::STORE_ID->value),
        ];
    }

    /**
     * Check if integration is configured.
     */
    public function isConfigured(): bool
    {
        return ! empty($this->app->get(ConfigurationEnum::BASE_URL->value))
            && ! empty($this->app->get(ConfigurationEnum::API_KEY->value))
            && ! empty($this->app->get(ConfigurationEnum::API_SECRET_KEY->value))
            && ! empty($this->company->get(ConfigurationEnum::STORE_ID->value));
    }
}
