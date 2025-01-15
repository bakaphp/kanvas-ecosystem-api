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
    protected static array $instances = [];

    /**
     * Singleton.
     */
    private function __construct()
    {
    }

        /**
     * Get unique key for the connection.
     */
    protected static function getConnectionKey(AppInterface $app, CompanyInterface $company, Regions $region): string 
    {
        return sprintf('app_%d-company_%d-region_%d', $app->id, $company->id, $region->id);
    }

    public static function getInstance(AppInterface $app, CompanyInterface $company, Regions $region): ShopifySDK
    {
        $key = self::getConnectionKey($app, $company, $region);

        if (!isset(self::$instances[$key])) {
            self::$instances[$key] = self::createInstance($app, $company, $region);
            
            // Optional: Implement cleanup for old connections
            if (count(self::$instances) > 100) { // Adjust limit as needed
                array_shift(self::$instances);
            }
        }

        return self::$instances[$key];
    }

    public static function getInstanceValidation(AppInterface $app, CompanyInterface $company, Regions $region): ShopifySDK
    {
        $key = self::getConnectionKey($app, $company, $region);
        self::$instances[$key] = self::createInstance($app, $company, $region);

        return self::$instances[$key];
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
            'AccessToken' => $clientSecret,
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
