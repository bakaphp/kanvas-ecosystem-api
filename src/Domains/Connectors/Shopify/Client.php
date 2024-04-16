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

        // its no supposed to explote with this
        if (empty($clientKey) || empty($clientSecret) || empty($shopUrl)) {
            throw new ValidationException('Shopify keys are not set for company' . $company->name . ' ' . $company->id .' '. 'on region' . $region->getId());
        }

        $shopifySdk = new ShopifySDK();

        return $shopifySdk->config([
            'ShopUrl' => $shopUrl,
            'ApiKey' => $clientKey,
            'Password' => $clientSecret,
        ]);
    }

    /**
     * Get zoho keys from company.
     */
    public static function getKeys(CompanyInterface $company, AppInterface $app, Regions $region): array
    {
        $clientCredentialNaming = CustomFieldEnum::SHOPIFY_API_CREDENTIAL->value ."-". $company->getId() ."-". $region->getId();

        $credential = $company->get($clientCredentialNaming);

        return [
            $credential[CustomFieldEnum::SHOPIFY_API_KEY->value],
            $credential[CustomFieldEnum::SHOPIFY_API_SECRET->value],
            $credential[CustomFieldEnum::SHOP_URL->value],
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
