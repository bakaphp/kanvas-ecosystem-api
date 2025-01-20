<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Shopify;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Shopify\Enums\CustomFieldEnum;
use Kanvas\Connectors\Shopify\Services\ShopifyConfigurationService;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Inventory\Regions\Models\Regions;
use Kanvas\Users\Models\UserCompanyApps;
use PHPShopify\ShopifySDK;

/**
 * Manages Shopify SDK instances for different company-app-region combinations.
 */
final class Client
{
    private const MAX_INSTANCES = 100;
    private static array $instances = [];

    private function __construct()
    {
    }

    /**
     * Get or create a Shopify SDK instance for the given app, company and region.
     */
    public static function getInstance(
        AppInterface $app,
        CompanyInterface $company,
        Regions $region
    ): ShopifySDK {
        $company = self::resolveCompany($app, $company);
        $key = self::getConnectionKey($app, $company, $region);

        if (! isset(self::$instances[$key])) {
            self::$instances[$key] = self::createInstance($app, $company, $region);
            self::cleanupOldInstances();
        }

        return self::$instances[$key];
    }

    /**
     * Create and validate a new Shopify SDK instance.
     */
    public static function getInstanceValidation(
        AppInterface $app,
        CompanyInterface $company,
        Regions $region
    ): ShopifySDK {
        $company = self::resolveCompany($app, $company);
        $key = self::getConnectionKey($app, $company, $region);

        return self::$instances[$key] = self::createInstance($app, $company, $region);
    }

    /**
     * Get Shopify API credentials for the given company, app and region.
     *
     * @throws ValidationException If credentials are not properly set
     * @return array{0: string, 1: string, 2: string} Array containing [API Key, API Secret, Shop URL]
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

    private static function getConnectionKey(
        AppInterface $app,
        CompanyInterface $company,
        Regions $region
    ): string {
        return sprintf('app_%d-company_%d-region_%d', $app->id, $company->id, $region->id);
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

        return (new ShopifySDK())->config([
            'ShopUrl' => $shopUrl,
            'ApiKey' => $clientKey,
            'Password' => $clientSecret,
            'AccessToken' => $clientSecret,
        ]);
    }

    private static function resolveCompany(AppInterface $app, CompanyInterface $company): CompanyInterface
    {
        if (! $app->get('USE_B2B_COMPANY_GROUP')) {
            return $company;
        }

        $globalCompanyId = $app->get('B2B_GLOBAL_COMPANY');
        $hasGlobalCompany = UserCompanyApps::where('companies_id', $globalCompanyId)
            ->where('apps_id', $app->getId())
            ->first();

        return $hasGlobalCompany ? Companies::getById($globalCompanyId) : $company;
    }

    private static function cleanupOldInstances(): void
    {
        if (count(self::$instances) > self::MAX_INSTANCES) {
            array_shift(self::$instances);
        }
    }
}
