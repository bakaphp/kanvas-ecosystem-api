<?php

declare(strict_types=1);

namespace Tests\Connectors\Traits;

use Baka\Contracts\AppInterface;
use Baka\Users\Contracts\UserInterface;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\WaSender\Client;
use Kanvas\Connectors\WaSender\Enums\ConfigurationEnum;

trait HasWaSenderConfiguration
{
    public function getClient(AppInterface $app, ?Companies $company = null, ?UserInterface $user = null)
    {
        $app->set(ConfigurationEnum::BASE_URL->value, getenv('TEST_WAS_SENDER_BASE_URL'));
        $app->set(ConfigurationEnum::API_KEY->value, getenv('TEST_WAS_SENDER_API_KEY'));

        return new Client(
            $app,
            $company
        );
    }
}
