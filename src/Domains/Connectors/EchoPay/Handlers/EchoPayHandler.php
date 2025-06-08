<?php

declare(strict_types=1);

namespace Kanvas\Connectors\EchoPay\Handlers;

use Kanvas\Connectors\Contracts\BaseIntegration;
use Kanvas\Connectors\EchoPay\Client;
use Kanvas\Connectors\EchoPay\Enums\ConfigurationEnum;
use Override;

class EchoPayHandler extends BaseIntegration
{
    #[Override]
    public function setup(): bool
    {
        $baseUrl = $this->data['baseUrl'];
        $clientId = $this->data['clientId'];
        $secret = $this->data['secret'];
        $merchantId = $this->data['merchantId'];
        $merchantKey = $this->data['merchantKey'];
        $merchantSecret = $this->data['merchantSecret'];
        $redirectUrl = $this->data['redirectUrl'];

        if (empty($baseUrl) || empty($clientId) || empty($secret)) {
            return false;
        }

        $this->app->set(ConfigurationEnum::BASE_URL->value, $baseUrl);
        $this->app->set(ConfigurationEnum::CLIENT_ID->value, $clientId);
        $this->app->set(ConfigurationEnum::SECRET->value, $secret);
        $this->app->set(ConfigurationEnum::MERCHANT_ID->value, $merchantId);
        $this->app->set(ConfigurationEnum::MERCHANT_KEY->value, $merchantKey);
        $this->app->set(ConfigurationEnum::MERCHANT_SECRET->value, $merchantSecret);
        $this->app->set(ConfigurationEnum::REDIRECT_URL->value, $redirectUrl);

        $client = new Client($this->app, $this->company);

        return $client->getAccessToken() !== null;
    }
}
