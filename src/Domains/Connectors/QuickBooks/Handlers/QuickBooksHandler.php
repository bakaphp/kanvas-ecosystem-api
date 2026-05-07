<?php

declare(strict_types=1);

namespace Kanvas\Connectors\QuickBooks\Handlers;

use Kanvas\Connectors\Contracts\BaseIntegration;
use Kanvas\Connectors\QuickBooks\Client;
use Kanvas\Connectors\QuickBooks\Enums\ConfigurationEnum;
use Kanvas\Exceptions\ValidationException;
use Override;

class QuickBooksHandler extends BaseIntegration
{
    #[Override]
    public function setup(): bool
    {
        $clientId = $this->data['client_id'] ?? null;
        $clientSecret = $this->data['client_secret'] ?? null;
        $accessToken = $this->data['access_token'] ?? null;
        $refreshToken = $this->data['refresh_token'] ?? null;
        $realmId = $this->data['realm_id'] ?? null;
        $baseUrl = $this->data['base_url'] ?? 'Production';

        if (empty($clientId) || empty($clientSecret) || empty($accessToken) || empty($realmId)) {
            throw new ValidationException('QuickBooks client ID, client secret, access token, and realm ID are required');
        }

        $this->app->set(ConfigurationEnum::QUICKBOOKS_CLIENT_ID->value, $clientId);
        $this->app->set(ConfigurationEnum::QUICKBOOKS_CLIENT_SECRET->value, $clientSecret);
        $this->app->set(ConfigurationEnum::QUICKBOOKS_ACCESS_TOKEN->value, $accessToken);
        $this->app->set(ConfigurationEnum::QUICKBOOKS_REFRESH_TOKEN->value, $refreshToken);
        $this->app->set(ConfigurationEnum::QUICKBOOKS_REALM_ID->value, $realmId);
        $this->app->set(ConfigurationEnum::QUICKBOOKS_BASE_URL->value, $baseUrl);

        $client = new Client($this->app);

        return $client->isConnected();
    }
}
