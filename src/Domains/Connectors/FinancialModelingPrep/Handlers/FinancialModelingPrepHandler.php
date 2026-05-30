<?php

declare(strict_types=1);

namespace Kanvas\Connectors\FinancialModelingPrep\Handlers;

use Exception;
use Kanvas\Connectors\Contracts\BaseIntegration;
use Kanvas\Connectors\FinancialModelingPrep\Client;
use Kanvas\Connectors\FinancialModelingPrep\Enums\ConfigurationEnum;
use Override;

class FinancialModelingPrepHandler extends BaseIntegration
{
    #[Override]
    public function setup(): bool
    {
        $key = $this->data['api_key'] ?? '';

        if (! Client::validateCredentials($key)) {
            throw new Exception('Failed to validate Financial Modeling Prep API key');
        }

        $this->app->set(ConfigurationEnum::FMP_API_KEY->value, $key);

        return true;
    }
}
