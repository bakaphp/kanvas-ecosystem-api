<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Brex\Services;

use Baka\Contracts\CompanyInterface;
use Kanvas\Connectors\Brex\DataTransferObject\Brex as BrexDto;
use Kanvas\Connectors\Brex\Enums\ConfigurationEnum;

class BrexService
{
    public static function isEnabled(CompanyInterface $company): bool
    {
        return (bool) $company->get(ConfigurationEnum::SYNC_ENABLED->value)
            && (string) $company->get(ConfigurationEnum::API_TOKEN->value) !== '';
    }

    /**
     * Credentials live on the COMPANY, not the app: each tenant holds its own Brex account and token.
     */
    public static function setup(BrexDto $dto): void
    {
        $dto->company->setEncrypted(ConfigurationEnum::API_TOKEN->value, $dto->apiToken);
        $dto->company->set(ConfigurationEnum::SYNC_ENABLED->value, '1');
        $dto->company->set(ConfigurationEnum::SYNC_APP_ID->value, (string) $dto->app->getId());
    }
}
