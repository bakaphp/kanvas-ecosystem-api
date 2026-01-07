<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Tookan\Handlers;

use Kanvas\Connectors\Contracts\BaseIntegration;
use Kanvas\Connectors\Tookan\Client;
use Kanvas\Connectors\Tookan\Enums\ConfigurationEnum;
use Override;

class TookanHandler extends BaseIntegration
{
    #[Override]
    public function setup(): bool
    {
        $baseUrl = $this->data['baseUrl'] ?? ConfigurationEnum::SANDBOX_URL->value;
        $apiKey = $this->data['apiKey'] ?? null;

        if (empty($apiKey)) {
            return false;
        }

        $this->app->set(ConfigurationEnum::BASE_URL->value, $baseUrl);
        $this->app->set(ConfigurationEnum::API_KEY->value, $apiKey);

        $client = new Client($this->app, $this->company);

        try {
            // Test connection with a simple endpoint that doesn't create data
            $response = $client->post(ConfigurationEnum::GET_ALL_FLEETS_PATH->value, []);

            return isset($response['status']) && $response['status'] == 200;
        } catch (\Exception $e) {
            return false;
        }
    }
}
