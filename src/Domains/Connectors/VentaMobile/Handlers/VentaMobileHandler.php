<?php

declare(strict_types=1);

namespace Kanvas\Connectors\VentaMobile\Handlers;

use Kanvas\Connectors\Contracts\BaseIntegration;
use Kanvas\Connectors\VentaMobile\Client;
use Kanvas\Connectors\VentaMobile\Enums\ConfigurationEnum;
use Kanvas\Exceptions\ValidationException;
use Override;

class VentaMobileHandler extends BaseIntegration
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
            throw new ValidationException('Failed to validate VentaMobile connection');
        }

        // Save the configuration
        $this->app->set(ConfigurationEnum::BASE_URL->value, $this->data['base_url']);
        $this->app->set(ConfigurationEnum::USERNAME->value, $this->data['username']);
        $this->app->set(ConfigurationEnum::PASSWORD->value, $this->data['password']);

        return true;
    }

    protected function validateCredentials(string $baseUrl, string $username, string $password): bool
    {
        if (empty($baseUrl) || empty($username) || empty($password)) {
            throw new ValidationException('All VentaMobile configuration fields are required');
        }

        return Client::validateCredentials($baseUrl, $username, $password);
    }
}
