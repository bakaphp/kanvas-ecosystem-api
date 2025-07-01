<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Mindee\Handlers;

use Kanvas\Connectors\Contracts\BaseIntegration;
use Kanvas\Connectors\Mindee\Enums\ConfigurationEnum;
use Kanvas\Exceptions\ValidationException;
use Override;

class MindeeHandler extends BaseIntegration
{
    #[Override]
    public function setup(): bool
    {
        $apiKey = $this->data['api_key'] ?? null;
        $accountName = $this->data['account_name'] ?? null;

        if (empty($apiKey) || empty($accountName)) {
            throw new ValidationException('API key and account name are required for Mindee.');
        }

        $this->app->set(ConfigurationEnum::API_KEY->value, $apiKey);
        $this->app->set(ConfigurationEnum::ACCOUNT_NAME->value, $accountName);

        return $this->app->get(ConfigurationEnum::API_KEY->value) === $apiKey &&
               $this->app->get(ConfigurationEnum::ACCOUNT_NAME->value) === $accountName;
    }
}
