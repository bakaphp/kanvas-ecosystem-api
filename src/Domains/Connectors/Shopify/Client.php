<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Shopify;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\Connectors\Shopify\Enums\CustomFieldEnum;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Guild\Enums\FlagEnum;
use Kanvas\Inventory\Regions\Models\Regions;
use PHPShopify\ShopifySDK;
use Symfony\Component\Cache\Adapter\RedisAdapter;
use Weble\ZohoClient\Enums\Region;
use Weble\ZohoClient\OAuthClient;
use Webleit\ZohoCrmApi\Client as ZohoCrmClient;
use Webleit\ZohoCrmApi\Enums\Mode;
use Webleit\ZohoCrmApi\ZohoCrm;

class Client
{
    protected static string $environment = Mode::PRODUCTION;
    protected static string $region = Region::US;

    /**
     * Singleton.
     */
    protected function __construct()
    {
    }

    /**
     * Connect to zoho CRM.
     */
    public static function getInstance(AppInterface $app, CompanyInterface $company, Regions $region): ShopifySDK
    {

        list($clientKey, $clientSecret, $shopUrl) = self::getKeys($company, $app, $region);

        if (empty($clientKey) || empty($clientSecret) || empty($refreshToken)) {
            $configZohoKey = $company->get(FlagEnum::APP_GLOBAL_ZOHO->value) ? 'app' : 'company';
            $configZohoKeyId = $company->get(FlagEnum::APP_GLOBAL_ZOHO->value) ? $app->name : $company->name;

            throw new ValidationException('Zoho keys are not set for ' . $configZohoKey . ' ' . $configZohoKeyId);
        }

        $shopifySdk = new ShopifySDK();
        return $shopifySdk->config([
            CustomFieldEnum::SHOP_URL => $shopUrl,
            CustomFieldEnum::SHOPIFY_API_KEY => $clientKey,
            CustomFieldEnum::SHOPIFY_API_SECRET => $clientSecret,
        ]);

        // $oAuthClient->setRefreshToken($refreshToken);
        // $oAuthClient->setRegion(self::$region);
        // $oAuthClient->useCache($cache);
        // $oAuthClient->offlineMode();

        // // setup the zoho crm client
        // $client = new ZohoCrmClient($oAuthClient);
        // $client->setMode(self::$environment);

        // return new ZohoCrm($client);
    }

    /**
     * Get zoho keys from company.
     */
    public static function getKeys(CompanyInterface $company, AppInterface $app, Regions $region): array
    {
        return [
            $company->get(CustomFieldEnum::SHOPIFY_API_KEY->value . $company->getId() . $region->getId()),
            $company->get(CustomFieldEnum::SHOPIFY_API_SECRET->value . $company->getId() . $region->getId()),
            $company->get(CustomFieldEnum::SHOP_URL->value . $company->getId() . $region->getId()),
        ];
    }

    /**
     * Set environment.
     */
    public static function setEnvironment(string $environment): void
    {
        self::$environment = $environment;
    }

    /**
     * Set region.
     */
    public function setRegion(string $region): void
    {
        self::$region = $region;
    }
}
