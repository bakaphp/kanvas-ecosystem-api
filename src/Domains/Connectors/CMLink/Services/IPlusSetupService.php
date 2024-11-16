<?php

declare(strict_types=1);

namespace Kanvas\Connectors\CMLink\Services;

use Kanvas\Connectors\CMLink\Enums\ConfigurationEnum;
use Kanvas\Connectors\NetSuite\DataTransferObject\CMLink;

class CMLinkSetupService
{
    public static function setup(CMLink $configuration): bool
    {
        return $configuration->app->set(
            ConfigurationEnum::APP_KEY->value,
            $configuration->app_key
        ) && $configuration->app->set(
            ConfigurationEnum::APP_SECRET->value,
            $configuration->app_secret
        ) && $configuration->app->set(
            ConfigurationEnum::APP_ID->value,
            $configuration->app_account_id
        ) && $configuration->app->set(
            ConfigurationEnum::APP_TYPE->value,
            $configuration->app_account_type
        );
    }
}
