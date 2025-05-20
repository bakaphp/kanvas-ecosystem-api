<?php

declare(strict_types=1);

namespace Kanvas\Connectors\AeroAmbulancia\Handlers;

use Kanvas\Connectors\AeroAmbulancia\Client;
use Kanvas\Connectors\AeroAmbulancia\Enums\ConfigurationEnum;
use Kanvas\Connectors\Contracts\BaseIntegration;
use Kanvas\Exceptions\ValidationException;
use Override;

class AeroAmbulanciaHandler extends BaseIntegration
{
    #[Override]
    public function setup(): bool
    {
        $validated = $this->validateCredentials(
            $this->data['base_url'] ?? '',
            $this->data['email'] ?? '',
            $this->data['password'] ?? ''
        );

        if (! $validated) {
            throw new ValidationException('Failed to validate Aero Ambulancia connection');
        }

        // Save the configuration

        return true;
    }

    protected function validateCredentials(string $baseUrl, string $username, string $password): bool
    {
        if (empty($baseUrl) || empty($username) || empty($password)) {
            throw new ValidationException('All VentaMobile configuration fields are required');
        }

        $this->app->set(ConfigurationEnum::BASE_URL->value, $this->data['base_url']);
        $this->app->set(ConfigurationEnum::EMAIL->value, $this->data['email']);
        $this->app->set(ConfigurationEnum::PASSWORD->value, (string) $this->data['password']);

        $client = new Client($this->app, $this->company);

        return ! empty($client->authenticate());
    }
}
