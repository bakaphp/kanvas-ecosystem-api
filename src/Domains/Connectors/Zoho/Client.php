<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Zoho;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\Connectors\Contracts\BaseClient;
use Kanvas\Connectors\Zoho\Enums\CustomFieldEnum;
use Kanvas\Connectors\Zoho\Services\ZohoConfigurationService;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Guild\Enums\FlagEnum;
use Kanvas\Regions\Models\Regions as KanvasRegions;
use Symfony\Component\Cache\Adapter\RedisAdapter;
use Weble\ZohoClient\Enums\Region;
use Weble\ZohoClient\OAuthClient;
use Webleit\ZohoCrmApi\Client as ZohoCrmClient;
use Webleit\ZohoCrmApi\Enums\Mode;
use Webleit\ZohoCrmApi\ZohoCrm;

class Client extends BaseClient
{
    protected static string $environment = Mode::PRODUCTION;
    protected static string $region = Region::US;
    protected static RedisAdapter $cache;
    protected static ?ZohoCrm $instance = null;

    /**
     * Singleton.
     */
    protected function __construct()
    {
    }

    /**
     * Connect to zoho CRM.
     */
    public static function getInstance(AppInterface $app, CompanyInterface $company, KanvasRegions $region): ZohoCrm
    {
        $redis = RedisAdapter::createConnection(
            'redis://' . config('database.redis.default.host')
        );

        $cache = new RedisAdapter(
            // the object that stores a valid connection to your Redis system
            $redis,
            // set namespace separate per company we don't have key conflict
            $namespace = 'company' . $company->getId(),
            // the default lifetime (in seconds) for cache items that do not define their
            // own lifetime, with a value 0 causing items to be stored indefinitely (i.e.
            // until RedisAdapter::clear() is invoked or the server(s) are purged)
            $defaultLifetime = 0
        );

        self::$cache = $cache;
        if (! self::$instance) {
            self::cleanupOldInstances();
            self::$instance = self::createInstance($app, $company, $region);
        }

        return self::$instance;
    }

    /**
     * Get zoho keys from company.
     */
    public static function getKeys(CompanyInterface $company, AppInterface $app, KanvasRegions $region): array
    {
        $credentialKey = ZohoConfigurationService::generateCredentialKey($company, $app, $region);
        $credential = $company->get($credentialKey);

        if (empty($credential) || ! is_array($credential)) {
            throw new ValidationException(
                sprintf(
                    'Zoho keys are not set for company %s (ID: %d) on region %s',
                    $company->name,
                    $company->id,
                    $region->name
                )
            );
        }

        return [
            $credential[CustomFieldEnum::CLIENT_ID->value],
            $credential[CustomFieldEnum::CLIENT_SECRET->value],
            $credential[CustomFieldEnum::REFRESH_TOKEN->value]
        ];
    }

    /**
     * Create and validate a new Shopify SDK instance.
     */
    public static function getInstanceValidation(
        AppInterface $app,
        CompanyInterface $company,
        KanvasRegions $region
    ): ZohoCrm {
        return self::$instance = self::getInstance($app, $company, $region);
    }

    protected static function createInstance(
        AppInterface $app,
        CompanyInterface $company,
        KanvasRegions $region
    ) {
        [$clientId, $clientSecret, $refreshToken] = self::getKeys($company, $app, $region);

        $oAuthClient = new OAuthClient(
            $clientId,
            $clientSecret,
        );

        $oAuthClient->setRegion(self::$region);
        $oAuthClient->useCache(self::$cache);
        $oAuthClient->setRefreshToken($refreshToken);
        $oAuthClient->offlineMode();

        // setup the zoho crm client
        $client = new ZohoCrmClient($oAuthClient);
        $client->setMode(self::$environment);
        return new ZohoCrm($client);
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

    private static function cleanupOldInstances(): void
    {
        if (self::$instance) {
            self::$instance = null;
        }
    }
}
