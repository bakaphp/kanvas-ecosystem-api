<?php

declare(strict_types=1);

namespace Tests\Connectors\Traits;

use Baka\Contracts\AppInterface;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\VinSolution\Client;
use Kanvas\Connectors\VinSolution\ClientCredential;
use Kanvas\Connectors\VinSolution\Enums\ConfigurationEnum;

trait HasVinsolutionConfiguration
{
    public function getClient(AppInterface $app)
    {
        $app->set(ConfigurationEnum::CLIENT_ID->value, getenv('TEST_VINSOLUTIONS_CLIENT_ID'));
        $app->set(ConfigurationEnum::CLIENT_SECRET->value, getenv('TEST_VINSOLUTIONS_CLIENT_SECRET'));
        $app->set(ConfigurationEnum::API_KEY->value, getenv('TEST_VINSOLUTIONS_API_KEY'));
        $app->set(ConfigurationEnum::API_KEY_DIGITAL_SHOWROOM->value, getenv('TEST_VINSOLUTIONS_API_KEY_DIGITAL_SHOWROOM'));

        $company = Companies::first();
        $company->set(ConfigurationEnum::COMPANY->value, getenv('TEST_VINSOLUTIONS_COMPANY_ID'));

        $company->user->set(ConfigurationEnum::getUserKey($company, $company->user), getenv('TEST_VINSOLUTIONS_USER_ID'));

        $vinCredential = ClientCredential::get(
            $company,
            $company->user,
            $app
        );

        return new Client(
            $vinCredential->dealer->id,
            $vinCredential->user->id,
            $app
        );
    }
}
