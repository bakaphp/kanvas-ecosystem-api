<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Mercury\Services;

use Baka\Contracts\CompanyInterface;
use Kanvas\Connectors\Mercury\DataTransferObject\Mercury as MercuryDto;
use Kanvas\Connectors\Mercury\Enums\ConfigurationEnum;

class MercuryService
{
    /**
     * A workflow rule can be wired to a Mercury activity on a company that never finished (or has since
     * revoked) its Mercury setup. Without this the activity would build a client with an empty token and
     * push a real customer's invoice at a 401.
     */
    public static function isEnabled(CompanyInterface $company): bool
    {
        return (bool) $company->get(ConfigurationEnum::SYNC_ENABLED->value)
            && (string) $company->get(ConfigurationEnum::API_TOKEN->value) !== '';
    }

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
        $dto->company->set(ConfigurationEnum::SYNC_APP_ID->value, (string) $dto->app->getId());
    }
}
