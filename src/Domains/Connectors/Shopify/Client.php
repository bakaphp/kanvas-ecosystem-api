<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Shopify;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\Connectors\Shopify\Enums\CustomFieldEnum;
use Kanvas\Connectors\Shopify\Services\ShopifyConfigurationService;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Inventory\Regions\Models\Regions;
use PHPShopify\ShopifySDK;

class Client
{
    protected static ?ShopifySDK $instance = null;

    /**
     * Singleton.
     */
    private function __construct()
    {
    }

    public static function getInstance(AppInterface $app, CompanyInterface $company, Regions $region): ShopifySDK
    {
        if (! self::$instance) {
            self::$instance = self::createInstance($app, $company, $region);
        }

        return self::$instance;
    }

    protected static function createInstance(AppInterface $app, CompanyInterface $company, Regions $region): ShopifySDK
    {
        list($clientKey, $clientSecret, $shopUrl) = self::getKeys($company, $app, $region);

        // its no supposed to explode with this
        if (empty($clientKey) || empty($clientSecret) || empty($shopUrl)) {
            throw new ValidationException(
                'Shopify keys are not set for company ' . $company->name . ' ' . $company->id . ' ' . 'on region ' . $region->name
            );
        }

        $shopifySdk = new ShopifySDK();

        return $shopifySdk->config([
            'ShopUrl' => $shopUrl,
            'ApiKey' => $clientKey,
            'Password' => $clientSecret,
        ]);
    }

    public static function getKeys(CompanyInterface $company, AppInterface $app, Regions $region): array
    {
        $clientCredentialNaming = ShopifyConfigurationService::generateCredentialKey($company, $app, $region);

        $credential = $company->get($clientCredentialNaming);

        // its no supposed to explode with this
        if (empty($credential) || ! is_array($credential)) {
            throw new ValidationException(
                'Shopify keys are not set for company ' . $company->name . ' ' . $company->id . ' ' . 'on region ' . $region->name
            );
        }

        return [
            $credential[CustomFieldEnum::SHOPIFY_API_KEY->value],
            $credential[CustomFieldEnum::SHOPIFY_API_SECRET->value],
            $credential[CustomFieldEnum::SHOP_URL->value],
        ];
    }
}
