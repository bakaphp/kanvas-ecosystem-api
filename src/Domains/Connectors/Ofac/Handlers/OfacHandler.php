<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Ofac\Handlers;

use Kanvas\Connectors\Contracts\BaseIntegration;
use Kanvas\Connectors\Ofac\Enums\ConfigurationEnum;
use Kanvas\Exceptions\ValidationException;
use Override;

class OfacHandler extends BaseIntegration
{
    #[Override]
    public function setup(): bool
    {
        $apiKey = $this->data['api_key'] ?? null;
        $baseUrl = $this->data['base_url'] ?? 'https://api.ofac.com'; // Default OFAC API URL

        if (empty($apiKey)) {
            throw new ValidationException('OFAC API key is required.');
        }

        // Save the configuration to the app
        $this->app->set(ConfigurationEnum::OFAC_API_KEY->value, $apiKey);
        $this->app->set(ConfigurationEnum::OFAC_BASE_URL->value, $baseUrl);

        return true;
    }
}
