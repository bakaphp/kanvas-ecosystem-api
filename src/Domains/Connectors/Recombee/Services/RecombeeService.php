<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Recombee\Services;

use Kanvas\Connectors\Recombee\DataTransferObject\RecombeeSetup;
use Kanvas\Connectors\Recombee\Enums\ConfigurationEnum;

class RecombeeService
{
    public static function setup(RecombeeSetup $data): bool
    {
        $data->app->set(ConfigurationEnum::RECOMBEE_DATABASE->value, $data->recombeeDatabase);
        $data->app->set(ConfigurationEnum::RECOMBEE_API_KEY->value, $data->privateToken);
        $data->app->set(ConfigurationEnum::RECOMBEE_REGION->value, $data->recombeeRegion);

        return true;
    }
}
