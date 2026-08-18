<?php

declare(strict_types=1);

namespace Tests\Connectors\Traits;

use Baka\Contracts\AppInterface;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\UniversalSeguros\Client;
use Kanvas\Connectors\UniversalSeguros\Enums\ConfigurationEnum;
use Kanvas\Connectors\UniversalSeguros\Enums\EnvironmentEnum;

trait HasUniversalSegurosConfiguration
{
    public function setupUniversalSegurosClient(AppInterface $app, ?Companies $company = null): Client
    {
        $company = $company ?? Companies::first();

        $company->set(ConfigurationEnum::ENVIRONMENT->value, getenv('TEST_UNIVERSAL_SEGUROS_ENVIRONMENT') ?: EnvironmentEnum::QA->value);
        $company->set(ConfigurationEnum::CLIENT_ID->value, getenv('TEST_UNIVERSAL_SEGUROS_CLIENT_ID'));
        $company->set(ConfigurationEnum::CLIENT_SECRET->value, getenv('TEST_UNIVERSAL_SEGUROS_CLIENT_SECRET'));
        $company->set(ConfigurationEnum::SCOPES->value, getenv('TEST_UNIVERSAL_SEGUROS_SCOPES') ?: ConfigurationEnum::defaultScopes());
        $company->set(ConfigurationEnum::VERIFY_SSL->value, getenv('TEST_UNIVERSAL_SEGUROS_VERIFY_SSL') ?: '1');

        return new Client($app, $company);
    }
}
