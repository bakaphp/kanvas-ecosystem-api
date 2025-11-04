<?php

declare(strict_types=1);

namespace Kanvas\Connectors\DealerSocket\Services;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\Connectors\DealerSocket\DataTransferObject\DealerSocket;
use Kanvas\Connectors\DealerSocket\Enums\CustomFieldEnum;
use Kanvas\Regions\Models\Regions;

class DealerSocketConfigurationService
{
    public static function setup(DealerSocket $data): bool
    {
        $clientCredentialNaming = self::generateCredentialKey($data->company, $data->app, $data->region);

        $configData = [
            CustomFieldEnum::DEALER_SOCKET_PUBLIC_KEY->value => $data->publicKey,
            CustomFieldEnum::DEALER_SOCKET_PRIVATE_KEY->value => $data->privateKey,
            CustomFieldEnum::DEALER_SOCKET_USERNAME->value => $data->username,
            CustomFieldEnum::DEALER_SOCKET_PASSWORD->value => $data->password,
            CustomFieldEnum::DEALER_SOCKET_DEALER_ID->value => $data->dealerId,
            CustomFieldEnum::DEALER_SOCKET_VENDOR_NAME->value => $data->vendorName,
        ];

        return $data->company->set(
            $clientCredentialNaming,
            $configData
        );
    }

    public static function generateCredentialKey(CompanyInterface $company, AppInterface $app, Regions $region): string
    {
        return CustomFieldEnum::DEALER_SOCKET_CREDENTIAL->value . '-' . $app->getId() . '-' . $company->getId() . '-' . $region->getId();
    }

    public static function getKey(string $key, CompanyInterface $company, AppInterface $app, Regions $region): string
    {
        return $key . '-' . $app->getId() . '-' . $company->getId() . '-' . $region->getId();
    }
}
