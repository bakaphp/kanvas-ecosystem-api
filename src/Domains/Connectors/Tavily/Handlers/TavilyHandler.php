<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Tavily\Handlers;

use Exception;
use Kanvas\Connectors\Contracts\BaseIntegration;
use Kanvas\Connectors\Tavily\Client;
use Kanvas\Connectors\Tavily\Enums\ConfigurationEnum;
use Override;

class TavilyHandler extends BaseIntegration
{
    #[Override]
    public function setup(): bool
    {
        $key = $this->data['api_key'] ?? '';

        if (! Client::validateCredentials($key)) {
            throw new Exception('Failed to validate Tavily API key');
        }

        $this->app->set(ConfigurationEnum::TAVILY_API_KEY->value, $key);

        return true;
    }
}
