<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Recombee\Services;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\Connectors\Contracts\BaseConfigurationService;
use Kanvas\Connectors\Contracts\IntegrationDtoInterface;
use Kanvas\Connectors\Recombee\Enums\ConfigurationEnum;
use Kanvas\Connectors\Recombee\Enums\CustomFieldEnum;
use Kanvas\Regions\Models\Regions;

class RecombeeConfigurationService extends BaseConfigurationService
{
    /**
     * Set the shopify credentials into companies custom fields.
     */
    public static function setup(IntegrationDtoInterface $data): bool
    {
        $clientCredentialNaming = self::generateCredentialKey($data->company, $data->app, $data->region);

        $configData = [
            ConfigurationEnum::RECOMBEE_DATABASE->value => $data->clientId,
            ConfigurationEnum::RECOMBEE_API_KEY->value => $data->apiSecret,
            ConfigurationEnum::RECOMBEE_REGION->value => $data->recombeeRegion,
        ];

        return $data->company->set(
            $clientCredentialNaming,
            $configData
        );
    }

    public static function generateCredentialKey(CompanyInterface $company, AppInterface $app, Regions $region): string
    {
        return CustomFieldEnum::RECOMBEE_CREDENTIAL->value . '-' . $app->getId() . '-' . $company->getId() . '-' . $region->getId();
    }

    public static function getKey(string $key, CompanyInterface $company, AppInterface $app, Regions $region): string
    {
        return $key . '-' . $app->getId() . '-' . $company->getId() . '-' . $region->getId();
    }
}
