<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Tavily\Handlers;

use Kanvas\Connectors\Contracts\BaseIntegration;
use Kanvas\Connectors\Tavily\Client;
use Kanvas\Connectors\Tavily\Enums\ConfigurationEnum;
use Kanvas\Exceptions\ValidationException;
use Override;

/**
 * The key is stored on the APP, not the company — Tavily is a shared research subscription billed to
 * whoever runs the platform, the same shape Apollo uses. A consequence worth knowing: any company
 * admin who runs this setup replaces the key every company in the app searches with. Move the write
 * to `$this->company` (and teach Client + TavilyReadinessService to read company-then-app, the way
 * RespondIO does) if a tenant ever needs to bill its own Tavily account.
 */
class TavilyHandler extends BaseIntegration
{
    #[Override]
    public function setup(): bool
    {
        $key = trim((string) ($this->data['api_key'] ?? ''));

        if ($key === '') {
            throw new ValidationException('Tavily API key is required.');
        }

        if (! Client::validateCredentials($key)) {
            throw new ValidationException('Invalid Tavily API key — Tavily rejected it.');
        }

        $this->app->set(ConfigurationEnum::TAVILY_API_KEY->value, $key);

        return true;
    }
}
