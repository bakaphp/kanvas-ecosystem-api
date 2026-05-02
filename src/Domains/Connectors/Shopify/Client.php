<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Shopify;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\Connectors\Shopify\Enums\CustomFieldEnum;
use Kanvas\Connectors\Shopify\Services\ShopifyConfigurationService;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Regions\Models\Regions;
use Kanvas\Souk\Services\B2BConfigurationService;
use PHPShopify\ShopifySDK;

final class Client
{
    private function __construct()
    {
    }

    public static function getInstance(
        AppInterface $app,
        CompanyInterface $company,
        Regions $region
    ): ShopifySDK {
        return self::createInstance(
            $app,
            self::resolveCompany($app, $company),
            $region
        );
    }

    /**
     * Get Shopify API credentials for the given company, app and region.
     *
     * @throws ValidationException If credentials are not properly set
     * @return array{mixed, mixed, mixed} Array containing [API Key, API Secret, Shop URL]
     */
    public static function getKeys(
        CompanyInterface $company,
        AppInterface $app,
        Regions $region
    ): array {
        $credentialKey = ShopifyConfigurationService::generateCredentialKey($company, $app, $region);
        $credential = $company->get($credentialKey);

        if (empty($credential) || ! is_array($credential)) {
            throw new ValidationException(
                sprintf(
                    'Shopify keys are not set for company %s (ID: %d) on region %s',
                    $company->name,
                    $company->id,
                    $region->name
                )
            );
        }

        return [
            $credential[CustomFieldEnum::SHOPIFY_API_KEY->value],
            $credential[CustomFieldEnum::SHOPIFY_API_SECRET->value],
            $credential[CustomFieldEnum::SHOP_URL->value],
        ];
    }

    private static function createInstance(
        AppInterface $app,
        CompanyInterface $company,
        Regions $region
    ): ShopifySDK {
        [$clientKey, $clientSecret, $shopUrl] = self::getKeys($company, $app, $region);

        if (empty($clientKey) || empty($clientSecret) || empty($shopUrl)) {
            throw new ValidationException(
                sprintf(
                    'Invalid Shopify credentials for company %s (ID: %d) on region %s',
                    $company->name,
                    $company->id,
                    $region->name
                )
            );
        }

        if ((bool) $app->get('shopify-use-access-token') === true) {
            return (new ShopifySDK())->config([
                'ShopUrl' => $shopUrl,
                'ApiKey' => $clientKey,
                'AccessToken' => $clientSecret,
            ]);
        }

        return (new ShopifySDK())->config([
            'ShopUrl' => $shopUrl,
            'ApiKey' => $clientKey,
            'Password' => $clientSecret,
            'AccessToken' => $clientSecret,
        ]);
    }

    private static function resolveCompany(AppInterface $app, CompanyInterface $company): CompanyInterface
    {
        return B2BConfigurationService::getConfiguredB2BCompany($app, $company);
    }
}
