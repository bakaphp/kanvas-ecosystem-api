<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Salesforce\Handlers;

use Kanvas\Connectors\Contracts\BaseIntegration;
use Kanvas\Connectors\Salesforce\Client;
use Kanvas\Connectors\Salesforce\Enums\ConfigurationEnum;
use Kanvas\Connectors\Salesforce\Enums\GrantTypeEnum;
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
        $grantType = GrantTypeEnum::tryFrom((string) ($this->data['grant_type'] ?? '')) ?? GrantTypeEnum::CLIENT_CREDENTIALS;

        if (empty($clientId) || empty($clientSecret)) {
            throw new ValidationException('Salesforce client_id/client_secret are not set for ' . $this->company->name);
        }

        // Client Credentials is server-to-server (Connected App's own "Run As" user) — there's no
        // user-authorized refresh_token in that flow, so it's only required for refresh_token.
        if ($grantType === GrantTypeEnum::REFRESH_TOKEN && empty($refreshToken)) {
            throw new ValidationException('Salesforce refresh_token is not set for ' . $this->company->name);
        }

        $this->company->set(ConfigurationEnum::CLIENT_ID->value, $clientId);
        $this->company->set(ConfigurationEnum::CLIENT_SECRET->value, $clientSecret);
        $this->company->set(ConfigurationEnum::GRANT_TYPE->value, $grantType->value);

        if ($grantType === GrantTypeEnum::REFRESH_TOKEN) {
            $this->company->set(ConfigurationEnum::REFRESH_TOKEN->value, $refreshToken);
        }

        if (! empty($loginUrl)) {
            $this->company->set(ConfigurationEnum::LOGIN_URL->value, $loginUrl);
        }

        // Verifies the credentials produce a valid token — not that a specific object (like
        // Organization) is visible, since object-level access depends on the org's permission set
        // and has nothing to do with whether the connection itself is valid.
        $salesforceClient = Client::getInstance($this->app, $this->company);
        $response = $salesforceClient->describeGlobal();

        return isset($response['sobjects']);
    }
}
