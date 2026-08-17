<?php

declare(strict_types=1);

namespace Kanvas\Connectors\ClaudeAgent\Handlers;

use Kanvas\Connectors\ClaudeAgent\Client;
use Kanvas\Connectors\ClaudeAgent\Enums\ConfigurationEnum;
use Kanvas\Connectors\Contracts\BaseIntegration;
use Kanvas\Exceptions\ValidationException;
use Override;

/**
 * Runs behind the generic `createIntegrationCompany` mutation — there is no bespoke GraphQL for
 * this connector. Per-agent config (GitHub token, allowed repos, budget) is set separately through
 * the generic `setAgentSetting` surface.
 */
class ClaudeAgentHandler extends BaseIntegration
{
    #[Override]
    public function setup(): bool
    {
        $apiKey = trim((string) ($this->data['api_key'] ?? ''));
        $baseUrl = trim((string) ($this->data['base_url'] ?? ''));

        if ($apiKey === '') {
            throw new ValidationException('A Claude API key is required.');
        }

        // Throws with the API's own message on a bad key, so the operator sees "invalid x-api-key"
        // rather than a generic setup failure.
        Client::validateCredentials($apiKey, $baseUrl !== '' ? $baseUrl : Client::DEFAULT_BASE_URL);

        // The key is a tenant secret (company-scoped); the endpoint is shared infra (app-scoped)
        // and only ever set when pointing at something other than the public API.
        $this->company->set(ConfigurationEnum::API_KEY->value, $apiKey);

        if ($baseUrl !== '') {
            $this->app->set(ConfigurationEnum::BASE_URL->value, rtrim($baseUrl, '/'));
        }

        return true;
    }
}
