<?php

declare(strict_types=1);

namespace Kanvas\Connectors\UniversalAssistance\Handlers;

use Kanvas\Connectors\UniversalAssistance\Client;
use Kanvas\Connectors\UniversalAssistance\Enums\ConfigurationEnum;
use Kanvas\Connectors\Contracts\BaseIntegration;
use Kanvas\Exceptions\ValidationException;
use Override;

class UniversalAssistanceHandler extends BaseIntegration
{
    #[Override]
    public function setup(): bool
    {
        $validated = $this->validateCredentials(
            $this->data['base_url'] ?? '',
            $this->data['username'] ?? '',
            $this->data['password'] ?? ''
        );

        if (! $validated) {
            throw new ValidationException('Failed to validate Universal Assistance connection');
        }

        // Save the configuration

        return true;
    }

    protected function validateCredentials(string $baseUrl, string $username, string $password): bool
    {
        if (empty($baseUrl) || empty($username) || empty($password)) {
            throw new ValidationException('All Universal Assistance configuration fields are required');
        }

        $this->app->set(ConfigurationEnum::BASE_URL->value, $this->data['base_url']);
        $this->app->set(ConfigurationEnum::USERNAME->value, $this->data['username']);
        $this->app->set(ConfigurationEnum::PASSWORD->value, (string) $this->data['password']);
        $this->app->set(ConfigurationEnum::ORGANIZATION->value, $this->data['organization'] ?? '');

        $client = new Client($this->app, $this->company);

        return $client->testConnection();
    }
}
