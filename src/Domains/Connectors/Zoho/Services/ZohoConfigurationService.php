<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Zoho\Services;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\Connectors\Contracts\BaseConfigurationService;
use Kanvas\Connectors\Contracts\IntegrationDtoInterface;
use Kanvas\Connectors\Zoho\Enums\CustomFieldEnum;
use Kanvas\Regions\Models\Regions;

class ZohoConfigurationService extends BaseConfigurationService
{
    /**
     * Set the shopify credentials into companies custom fields.
     */
    public static function setup(IntegrationDtoInterface $data): bool
    {
        $clientCredentialNaming = self::generateCredentialKey($data->company, $data->app, $data->region);

        $configData = [
            CustomFieldEnum::CLIENT_ID->value => $data->apiKey,
            CustomFieldEnum::CLIENT_SECRET->value => $data->apiSecret,
        ];

        return $data->company->set(
            $clientCredentialNaming,
            $configData
        );
    }

    public static function generateCredentialKey(CompanyInterface $company, AppInterface $app, Regions $region): string
    {
        return CustomFieldEnum::ZOHO_API_CREDENTIAL->value . '-' . $app->getId() . '-' . $company->getId() . '-' . $region->getId();
    }

    public static function getKey(string $key, CompanyInterface $company, AppInterface $app, Regions $region): string
    {
        return $key . '-' . $app->getId() . '-' . $company->getId() . '-' . $region->getId();
    }
}
