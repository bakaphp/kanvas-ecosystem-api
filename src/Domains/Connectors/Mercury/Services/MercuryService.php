<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Mercury\Services;

use Kanvas\Connectors\Mercury\DataTransferObject\Mercury as MercuryDto;
use Kanvas\Connectors\Mercury\Enums\ConfigurationEnum;

class MercuryService
{
    /**
     * Credentials live on the COMPANY, not the app: one Kanvas app serves many tenants and each holds its
     * own Mercury organization with its own token.
     */
    public static function setup(MercuryDto $dto): void
    {
        $dto->company->set(ConfigurationEnum::API_TOKEN->value, $dto->apiToken);

        if ($dto->baseUrl !== null) {
            $dto->company->set(ConfigurationEnum::BASE_URL->value, $dto->baseUrl);
        }

        $dto->company->set(ConfigurationEnum::SYNC_ENABLED->value, '1');
    }
}
