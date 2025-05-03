<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Recombee\Handlers;

use Kanvas\Connectors\Contracts\BaseIntegration;
use Kanvas\Connectors\Recombee\Client;
use Kanvas\Connectors\Recombee\Enums\ConfigurationEnum;
use Kanvas\Exceptions\ValidationException;
use Override;
use Recombee\RecommApi\Client as RecommApiClient;

class RecombeeHandler extends BaseIntegration
{
    #[Override]
    public function setup(): bool
    {
        $database = $this->data['database'] ?? null;
        $apiKey = $this->data['api_key'] ?? null;
        $region = $this->data['region'] ?? null;

        if (empty($database) || empty($apiKey) || empty($region)) {
            throw new ValidationException('Recombee database and api key are required');
        }

        $this->app->set(ConfigurationEnum::RECOMBEE_DATABASE->value, $database);
        $this->app->set(ConfigurationEnum::RECOMBEE_API_KEY->value, $apiKey);
        $this->app->set(ConfigurationEnum::RECOMBEE_REGION->value, $region);

        return new Client(
            $this->app,
            $database,
            $apiKey,
            $region
        )->getClient() instanceof RecommApiClient;
    }
}
