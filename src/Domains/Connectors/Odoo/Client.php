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
 * Every Company holds its own Odoo credentials, so the client is rebuilt per call rather than
 * held in a static property (Octane rule — see the kanvas-connector skill). Only the resolved
 * `uid` is cached, in Laravel's cache backend, since authenticating is the one round-trip worth
 * avoiding on every call.
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

    public static function forgetUid(AppInterface $app, CompanyInterface $company): void
    {
        Cache::forget(self::uidCacheKey($app, $company, self::getKeys($company)));
    }

    private static function getUid(AppInterface $app, CompanyInterface $company, array $config): int
    {
        return Cache::remember(
            self::uidCacheKey($app, $company, $config),
            self::UID_TTL_SECONDS,
            function () use ($config) {
                $uid = OdooApiClient::authenticate(
                    $config['url'],
                    $config['database'],
                    $config['username'],
                    $config['api_key'],
                );

                if ($uid === null) {
                    throw new ValidationException('Unable to authenticate against Odoo — credentials were rejected');
                }

                return $uid;
            },
        );
    }

    /**
     * Keyed by app as well as company: a Company can belong to more than one App and each App's
     * Odoo integration is configured independently, so companies_id alone would let one App's
     * uid leak into another. The credential fingerprint on top is what makes a rotated
     * username/api key resolve to a fresh entry — without it a rotation serves a uid the new
     * credentials don't authenticate as for the rest of the TTL, i.e. a 12h outage.
     */
    private static function uidCacheKey(AppInterface $app, CompanyInterface $company, array $config): string
    {
        $fingerprint = substr(hash('sha256', implode('|', $config)), 0, 12);

        return 'odoo_uid_' . $app->getId() . '_' . $company->getId() . '_' . $fingerprint;
    }
}
