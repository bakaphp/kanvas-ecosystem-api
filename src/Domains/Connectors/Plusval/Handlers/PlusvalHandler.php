<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Plusval\Handlers;

use Kanvas\Connectors\Contracts\BaseIntegration;
use Kanvas\Connectors\Plusval\Client;
use Kanvas\Connectors\Plusval\Enums\ConfigurationEnum;
use Kanvas\Exceptions\ValidationException;
use Override;

class PlusvalHandler extends BaseIntegration
{
    #[Override]
    public function setup(): bool
    {
        $validated = $this->validateCredentials(
            $this->data['base_url'] ?? '',
            $this->data['api_key'] ?? '',
        );

        if (! $validated) {
            throw new ValidationException('Failed to validate Plusval connection');
        }

        // Save the configuration
        $this->app->set(ConfigurationEnum::BASE_URL->value, $this->data['base_url']);
        $this->app->set(ConfigurationEnum::API_KEY->value, $this->data['api_key']);

        return true;
    }

    protected function validateCredentials(string $baseUrl, string $apiKey): bool
    {
        if (empty($baseUrl) || empty($apiKey)) {
            throw new ValidationException('All Plusval configuration fields are required');
        }

        //return Client::validateCredentials($baseUrl, $apiKey);
        return true;
    }
}
