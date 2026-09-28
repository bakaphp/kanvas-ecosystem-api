<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Jina\Handlers;

use Kanvas\Connectors\Contracts\BaseIntegration;
use Kanvas\Connectors\Jina\Client;
use Kanvas\Connectors\Jina\Enums\ConfigurationEnum;
use Kanvas\Exceptions\ValidationException;
use Override;

/**
 * Stored on the APP, not the company — Jina is a shared research subscription billed to whoever runs
 * the platform, the same shape Apollo and Tavily use. The same consequence applies: a company admin
 * running this setup replaces the key every company in the app reads with.
 */
class JinaHandler extends BaseIntegration
{
    #[Override]
    public function setup(): bool
    {
        $key = trim((string) ($this->data['api_key'] ?? ''));

        if ($key === '') {
            throw new ValidationException('Jina API key is required.');
        }

        if (! Client::validateCredentials($key)) {
            throw new ValidationException('Invalid Jina API key — Jina rejected it.');
        }

        $this->app->set(ConfigurationEnum::JINA_API_KEY->value, $key);

        return true;
    }
}
