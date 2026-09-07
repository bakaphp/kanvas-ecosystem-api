<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Trello\Handlers;

use Kanvas\Connectors\Contracts\BaseIntegration;
use Kanvas\Connectors\Trello\Client;
use Kanvas\Connectors\Trello\Enums\ConfigurationEnum;
use Kanvas\Exceptions\ValidationException;
use Override;

/**
 * Connects Trello through the generic `integrationCompany` mutation. Credentials are a Developer
 * API Key (per Trello "Power-Up"/app) plus a user Token (https://trello.com/app-key) — both are
 * company-scoped since a card should be created under whichever member authorized the token.
 */
class TrelloHandler extends BaseIntegration
{
    #[Override]
    public function setup(): bool
    {
        $apiKey = trim((string) ($this->data['api_key'] ?? ''));
        $apiToken = trim((string) ($this->data['api_token'] ?? ''));

        if ($apiKey === '' || $apiToken === '') {
            throw new ValidationException('Trello API key and token are required.');
        }

        if (! Client::validateCredentials($apiKey, $apiToken)) {
            throw new ValidationException('Failed to validate the Trello connection.');
        }

        $this->company->set(ConfigurationEnum::API_KEY->value, $apiKey);
        $this->company->set(ConfigurationEnum::API_TOKEN->value, $apiToken);

        return true;
    }
}
