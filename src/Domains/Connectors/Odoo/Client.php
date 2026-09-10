<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Odoo;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Illuminate\Support\Facades\Cache;
use Kanvas\Connectors\Odoo\Enums\ConfigurationEnum;
use Kanvas\Connectors\Odoo\Services\OdooApiClient;
use Kanvas\Exceptions\ValidationException;

/**
 * Kanvas is multi-tenant: every Company holds its own Odoo instance credentials, so this resolves
 * fresh per call rather than caching an `OdooApiClient` instance (Octane rule — see the
 * kanvas-connector skill's "never cache SDK instances in static properties" section). Only the
 * resolved `uid` is cached (via Laravel's cache backend, not a static property), since
 * authenticating is the one network round-trip worth avoiding on every call.
 */
class Client
{
    private const int UID_TTL_SECONDS = 60 * 60 * 12;

    public static function getInstance(AppInterface $app, CompanyInterface $company): OdooApiClient
    {
        $config = self::getKeys($company);
        $uid = self::getUid($app, $company, $config);

        return new OdooApiClient(
            baseUrl: $config['url'],
            database: $config['database'],
            uid: $uid,
            apiKey: $config['api_key'],
        );
    }

    /**
     * @return array{url: string, database: string, username: string, api_key: string}
     */
    public static function getKeys(CompanyInterface $company): array
    {
        $url = (string) $company->get(ConfigurationEnum::URL->value);
        $database = (string) $company->get(ConfigurationEnum::DATABASE->value);
        $username = (string) $company->get(ConfigurationEnum::USERNAME->value);
        $apiKey = (string) $company->get(ConfigurationEnum::API_KEY->value);

        if ($url === '' || $database === '' || $username === '' || $apiKey === '') {
            throw new ValidationException('Odoo keys are not set for ' . $company->name);
        }

        return [
            'url' => $url,
            'database' => $database,
            'username' => $username,
            'api_key' => $apiKey,
        ];
    }

    private static function getUid(AppInterface $app, CompanyInterface $company, array $config): int
    {
        return Cache::remember(
            self::uidCacheKey($app, $company),
            self::UID_TTL_SECONDS,
            function () use ($config) {
                $uid = OdooApiClient::authenticate($config['url'], $config['database'], $config['username'], $config['api_key']);

                if ($uid === null) {
                    throw new ValidationException('Unable to authenticate against Odoo — credentials were rejected');
                }

                return $uid;
            },
        );
    }

    // A Company can belong to more than one App, and each App's Odoo integration is configured
    // independently — keying the cache by companies_id alone would let one App's cached uid leak
    // into another App sharing the same Company (same reasoning as Salesforce\Client).
    private static function uidCacheKey(AppInterface $app, CompanyInterface $company): string
    {
        return 'odoo_uid_' . $app->getId() . '_' . $company->getId();
    }
}
