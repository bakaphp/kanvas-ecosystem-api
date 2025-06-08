<?php

declare(strict_types=1);

namespace Kanvas\Connectors\PasoRapido\Handlers;

use Kanvas\Connectors\Contracts\BaseIntegration;
use Kanvas\Connectors\PasoRapido\Client;
use Kanvas\Connectors\PasoRapido\Enums\ConfigurationEnum;
use Override;

class PasoRapidoHandler extends BaseIntegration
{
    #[Override]
    public function setup(): bool
    {
        $baseUrl = $this->data['baseUrl'];
        $clientId = $this->data['clientId'];
        $secret = $this->data['secret'];

        if (empty($baseUrl) || empty($clientId) || empty($secret)) {
            return false;
        }

        $this->app->set(ConfigurationEnum::BASE_URL->value, $baseUrl);
        $this->app->set(ConfigurationEnum::CLIENT_ID->value, $clientId);
        $this->app->set(ConfigurationEnum::SECRET->value, $secret);

        $client = new Client($this->app, $this->company);

        return $client->getAccessToken() !== null;
    }
}
