<?php

declare(strict_types=1);

namespace Kanvas\Connectors\VoiceBridge\Handlers;

use Kanvas\Connectors\Contracts\BaseIntegration;
use Kanvas\Connectors\VoiceBridge\Client;
use Kanvas\Connectors\VoiceBridge\Enums\ConfigurationEnum;
use Kanvas\Exceptions\ValidationException;
use Override;

class VoiceBridgeHandler extends BaseIntegration
{
    #[Override]
    public function setup(): bool
    {
        $apiKey = $this->data['api_key'] ?? null;
        $baseUrl = $this->data['base_url'] ?? null;
        $companyId = $this->data['company_id'] ?? null;

        if (empty($apiKey)) {
            throw new ValidationException('VoiceBridge API key is required.');
        }

        if (empty($baseUrl)) {
            throw new ValidationException('VoiceBridge base URL is required.');
        }

        $this->app->set(ConfigurationEnum::API_KEY->value, $apiKey);
        $this->app->set(ConfigurationEnum::BASE_URL->value, $baseUrl);

        if (! empty($companyId)) {
            $this->app->set(ConfigurationEnum::COMPANY_ID->value, $companyId);
        }

        return Client::validateCredentials($apiKey, $baseUrl);
    }
}
