<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Salesforce\Handlers;

use Kanvas\Connectors\Contracts\BaseIntegration;
use Kanvas\Connectors\Salesforce\Client;
use Kanvas\Connectors\Salesforce\Enums\ConfigurationEnum;
use Kanvas\Exceptions\ValidationException;
use Override;

class SalesforceHandler extends BaseIntegration
{
    #[Override]
    public function setup(): bool
    {
        $clientId = $this->data['client_id'] ?? null;
        $clientSecret = $this->data['client_secret'] ?? null;
        $refreshToken = $this->data['refresh_token'] ?? null;
        $loginUrl = $this->data['login_url'] ?? null;

        if (empty($clientId) || empty($clientSecret) || empty($refreshToken)) {
            throw new ValidationException('Salesforce keys are not set for ' . $this->company->name);
        }

        $this->company->set(ConfigurationEnum::CLIENT_ID->value, $clientId);
        $this->company->set(ConfigurationEnum::CLIENT_SECRET->value, $clientSecret);
        $this->company->set(ConfigurationEnum::REFRESH_TOKEN->value, $refreshToken);

        if (! empty($loginUrl)) {
            $this->company->set(ConfigurationEnum::LOGIN_URL->value, $loginUrl);
        }

        $salesforceClient = Client::getInstance($this->app, $this->company);
        $response = $salesforceClient->query('SELECT Id FROM Organization LIMIT 1');

        return isset($response['records']);
    }
}
