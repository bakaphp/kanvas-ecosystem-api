<?php

declare(strict_types=1);

namespace Tests\Connectors\Traits;

use Baka\Contracts\AppInterface;
use Baka\Users\Contracts\UserInterface;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Elead\Client;
use Kanvas\Connectors\Elead\Enums\ConfigurationEnum;
use Kanvas\Connectors\Elead\Enums\CustomFieldEnum;

trait HasELeadConfiguration
{
    public function getClient(AppInterface $app, ?Companies $company = null, ?UserInterface $user = null)
    {
        $company->set(CustomFieldEnum::COMPANY->value, getenv('TEST_ELEAD_SUBSCRIPION_ID'));
        $app->set(ConfigurationEnum::ELEAD_API_KEY->value, getenv('TEST_ELEAD_API_KEY'));
        $app->set(ConfigurationEnum::ELEAD_API_SECRET->value, getenv('TEST_ELEAD_API_SECRET'));
        $app->set(ConfigurationEnum::ELEAD_DEV_MODE->value, getenv('TEST_ELEAD_DEV_MODE'));

        return new Client(
            $app,
            $company
        );
    }
}
