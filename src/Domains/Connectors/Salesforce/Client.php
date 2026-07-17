<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Salesforce;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Kanvas\Connectors\Salesforce\Enums\ConfigurationEnum;
use Kanvas\Connectors\Salesforce\Services\SalesforceApiClient;
use Kanvas\Exceptions\ValidationException;

/**
 * Kanvas is multi-tenant: every Company holds its own Salesforce Connected App credentials
 * (client_id/client_secret/refresh_token), so a single-tenant SDK like omniphx/forrest doesn't
 * fit — this is a lightweight per-company Guzzle-backed (via Laravel's Http client) OAuth2
 * refresh-token wrapper instead.
 */
class Client
{
    private const int TOKEN_TTL_SECONDS = 90 * 60;

    public static function getInstance(AppInterface $app, CompanyInterface $company): SalesforceApiClient
    {
        $config = self::getKeys($company);
        $token = self::getAccessToken($app, $company, $config);

        return new SalesforceApiClient(
            instanceUrl: $token['instance_url'],
            accessToken: $token['access_token'],
            apiVersion: $config['api_version'],
            onUnauthorized: fn () => self::refreshAccessToken($app, $company, $config),
        );
    }

    public static function getKeys(CompanyInterface $company): array
    {
        $clientId = (string) $company->get(ConfigurationEnum::CLIENT_ID->value);
        $clientSecret = (string) $company->get(ConfigurationEnum::CLIENT_SECRET->value);
        $refreshToken = (string) $company->get(ConfigurationEnum::REFRESH_TOKEN->value);

        if ($clientId === '' || $clientSecret === '' || $refreshToken === '') {
            throw new ValidationException('Salesforce keys are not set for ' . $company->name);
        }

        return [
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'refresh_token' => $refreshToken,
            'login_url' => (string) ($company->get(ConfigurationEnum::LOGIN_URL->value) ?: 'https://login.salesforce.com'),
            'api_version' => (string) ($company->get(ConfigurationEnum::API_VERSION->value) ?: 'v60.0'),
        ];
    }

    private static function getAccessToken(AppInterface $app, CompanyInterface $company, array $config): array
    {
        return Cache::remember(
            self::tokenCacheKey($app, $company),
            self::TOKEN_TTL_SECONDS,
            fn () => self::requestAccessToken($company, $config)
        );
    }

    private static function refreshAccessToken(AppInterface $app, CompanyInterface $company, array $config): array
    {
        Cache::forget(self::tokenCacheKey($app, $company));

        return self::getAccessToken($app, $company, $config);
    }

    // A Company can belong to more than one App (users_companies_apps has a composite
    // (companies_id, apps_id) key), and each App's Salesforce integration is configured
    // independently — keying the cache by companies_id alone would let one App's cached
    // token leak into another App sharing the same Company.
    private static function tokenCacheKey(AppInterface $app, CompanyInterface $company): string
    {
        return 'salesforce_token_' . $app->getId() . '_' . $company->getId();
    }

    private static function requestAccessToken(CompanyInterface $company, array $config): array
    {
        $response = Http::asForm()->post($config['login_url'] . '/services/oauth2/token', [
            'grant_type' => 'refresh_token',
            'client_id' => $config['client_id'],
            'client_secret' => $config['client_secret'],
            'refresh_token' => $config['refresh_token'],
        ]);

        if ($response->failed()) {
            throw new ValidationException('Unable to refresh Salesforce access token: ' . $response->body());
        }

        // Orgs with refresh token rotation enabled invalidate the refresh token just used and
        // return a new one that must replace it — otherwise every following grant fails with
        // invalid_grant even though nothing else changed.
        $rotatedRefreshToken = $response->json('refresh_token');
        if (is_string($rotatedRefreshToken) && $rotatedRefreshToken !== '' && $rotatedRefreshToken !== $config['refresh_token']) {
            $company->set(ConfigurationEnum::REFRESH_TOKEN->value, $rotatedRefreshToken);
        }

        return [
            'access_token' => (string) $response->json('access_token'),
            'instance_url' => (string) $response->json('instance_url'),
        ];
    }
}
