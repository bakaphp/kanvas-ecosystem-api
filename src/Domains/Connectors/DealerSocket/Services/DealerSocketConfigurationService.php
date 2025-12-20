<?php

declare(strict_types=1);

namespace Kanvas\Connectors\DealerSocket\Services;

use Kanvas\Connectors\DealerSocket\DataTransferObject\DealerSocket;
use Kanvas\Connectors\DealerSocket\Enums\CustomFieldEnum;

class DealerSocketConfigurationService
{
    public static function setup(DealerSocket $data): bool
    {
        $clientCredentialNaming = CustomFieldEnum::DEALER_SOCKET_CREDENTIAL->value;

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
}
