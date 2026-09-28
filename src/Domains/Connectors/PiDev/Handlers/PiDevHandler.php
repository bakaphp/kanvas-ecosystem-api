<?php

declare(strict_types=1);

namespace Kanvas\Connectors\PiDev\Handlers;

use Kanvas\Connectors\Contracts\BaseIntegration;
use Kanvas\Connectors\PiDev\Client;
use Kanvas\Connectors\PiDev\Enums\ConfigurationEnum;
use Kanvas\Exceptions\ValidationException;
use Override;

class PiDevHandler extends BaseIntegration
{
    #[Override]
    public function setup(): bool
    {
        $baseUrl = (string) ($this->data['base_url'] ?? '');
        $apiToken = (string) ($this->data['api_token'] ?? '');

        if ($baseUrl === '' || $apiToken === '') {
            throw new ValidationException('pi.dev base URL and API token are required');
        }

        if (! Client::validateCredentials($baseUrl, $apiToken)) {
            throw new ValidationException('Failed to validate pi.dev connection');
        }

        // Endpoint is shared infra (app-scoped); the bearer is a tenant secret (company-scoped).
        $this->app->set(ConfigurationEnum::BASE_URL->value, $baseUrl);
        $this->company->set(ConfigurationEnum::API_TOKEN->value, $apiToken);

        return true;
    }
}
