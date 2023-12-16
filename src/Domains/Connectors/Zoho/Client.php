<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Zoho;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\Connectors\Zoho\Enums\CustomFieldEnum;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Guild\Enums\FlagEnum;
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
    public static function getInstance(AppInterface $app, CompanyInterface $company): ZohoCrm
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

        list($clientId, $clientSecret, $refreshToken) = self::getKeys($company, $app);

        if (empty($clientId) || empty($clientSecret) || empty($refreshToken)) {
            $configZohoKey = $company->get(FlagEnum::APP_GLOBAL_ZOHO->value) ? 'app' : 'company';
            $configZohoKeyId = $company->get(FlagEnum::APP_GLOBAL_ZOHO->value) ? $app->name : $company->name;

            throw new ValidationException('Zoho keys are not set for ' . $configZohoKey . ' ' . $configZohoKeyId);
        }

        $oAuthClient = new OAuthClient(
            $clientId,
            $clientSecret,
        );

        $oAuthClient->setRefreshToken($refreshToken);
        $oAuthClient->setRegion(self::$region);
        $oAuthClient->useCache($cache);
        $oAuthClient->offlineMode();

        // setup the zoho crm client
        $client = new ZohoCrmClient($oAuthClient);
        $client->setMode(self::$environment);

        return new ZohoCrm($client);
    }

    /**
     * Get zoho keys from company.
     */
    public static function getKeys(CompanyInterface $company, AppInterface $app): array
    {
        $config = $company;

        if ($company->get(FlagEnum::APP_GLOBAL_ZOHO->value)) {
            $config = $app;
        }

        return [
            $config->get(CustomFieldEnum::CLIENT_ID->value),
            $config->get(CustomFieldEnum::CLIENT_SECRET->value),
            $config->get(CustomFieldEnum::REFRESH_TOKEN->value),
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
