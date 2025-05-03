<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Credit700\Handlers;

use Kanvas\Connectors\Contracts\BaseIntegration;
use Kanvas\Connectors\Credit700\Client;
use Kanvas\Connectors\Credit700\Enums\ConfigurationEnum;
use Kanvas\Exceptions\ValidationException;
use Override;

class Credit700Handler extends BaseIntegration
{
    #[Override]
    public function setup(): bool
    {
        $clientId = $this->data['client_id'] ?? null;
        $clientSecret = $this->data['client_secret'] ?? null;

        if (empty($clientId) || empty($clientSecret)) {
            throw new ValidationException('700Credit credentials are not set for ' . $this->app->name);
        }

        $this->app->set(ConfigurationEnum::CLIENT_ID->value, $clientId);
        $this->app->set(ConfigurationEnum::CLIENT_SECRET->value, $clientSecret);

        $client = new Client(
            app: $this->app,
            company: $this->company
        );

        return $client->generateToken() !== '';
    }
}
