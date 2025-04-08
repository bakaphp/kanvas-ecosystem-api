<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Recombee\Services;

use Kanvas\Connectors\Recombee\DataTransferObject\RecombeeSetup;
use Kanvas\Connectors\Recombee\Enums\ConfigurationEnum;

class RecombeeService
{
    public static function recombeeSetup(RecombeeSetup $data): bool
    {
        $clientCredentialNaming = RecombeeConfigurationService::generateCredentialKey($data->company, $data->app, $data->region);

        $configData = [
            ConfigurationEnum::RECOMBEE_DATABASE->value => $data->recombeeDatabase,
            ConfigurationEnum::RECOMBEE_API_KEY->value => $data->privateToken,
            ConfigurationEnum::RECOMBEE_REGION->value => $data->recombeeRegion,
        ];

        return $data->company->set(
            $clientCredentialNaming,
            $configData
        );
    }
}
