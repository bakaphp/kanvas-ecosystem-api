<?php

declare(strict_types=1);

use Kanvas\Connectors\IPlus\Enums\ConfigurationEnum;
use Kanvas\Connectors\NetSuite\DataTransferObject\IPlus;

class IPlusSetupService
{
    public static function setup(IPlus $configuration): bool
    {
        return $configuration->app->set(
            ConfigurationEnum::CLIENT_ID->value,
            $configuration->client_id
        ) && $configuration->app->set(
            ConfigurationEnum::CLIENT_SECRET->value,
            $configuration->client_secret
        );
    }
}
