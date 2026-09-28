<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Supadata\Handlers;

use Kanvas\Connectors\Contracts\BaseIntegration;
use Kanvas\Connectors\Supadata\Client;
use Kanvas\Connectors\Supadata\Enums\ConfigurationEnum;
use Kanvas\Exceptions\ValidationException;
use Override;

/**
 * Setup writes the key to the COMPANY, not the app — unlike Tavily and Jina, which are shared research
 * subscriptions billed to whoever runs the platform. Supadata meters and bills transcription per
 * minute of media, so each tenant brings its own account and spends its own credits; writing to the
 * app here would let one company's admin put every other company in the app on their bill.
 *
 * The app-level key still exists as the platform-wide default and is what `Client` falls back to for
 * companies that have not connected their own — it is set directly by whoever runs the platform
 * rather than through this handler.
 */
class SupadataHandler extends BaseIntegration
{
    #[Override]
    public function setup(): bool
    {
        $key = trim((string) ($this->data['api_key'] ?? ''));

        if ($key === '') {
            throw new ValidationException('Supadata API key is required.');
        }

        if (! Client::validateCredentials($key)) {
            throw new ValidationException('Invalid Supadata API key — Supadata rejected it.');
        }

        $this->company->set(ConfigurationEnum::SUPADATA_API_KEY->value, $key);

        return true;
    }
}
