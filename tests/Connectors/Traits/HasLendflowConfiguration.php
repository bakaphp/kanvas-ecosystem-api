<?php

declare(strict_types=1);

namespace Tests\Connectors\Traits;

use Baka\Contracts\AppInterface;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Lendflow\Client;
use Kanvas\Connectors\Lendflow\Enums\ConfigurationEnum;

trait HasLendflowConfiguration
{
    public function getLendflowClient(AppInterface $app, Companies $company): Client
    {
        $company->set(ConfigurationEnum::API_KEY->value, (string) getenv('TEST_LENDFLOW_API_KEY'));
        $company->set(
            ConfigurationEnum::WORKFLOW_TEMPLATE_ID->value,
            (string) getenv('TEST_LENDFLOW_WORKFLOW_TEMPLATE_ID')
        );
        $company->set(ConfigurationEnum::USE_SANDBOX->value, 1);

        return new Client($app, $company);
    }
}
