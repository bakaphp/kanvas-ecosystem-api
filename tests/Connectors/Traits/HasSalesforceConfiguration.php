<?php

declare(strict_types=1);

namespace Tests\Connectors\Traits;

use Baka\Contracts\CompanyInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Kanvas\Connectors\Salesforce\Enums\ConfigurationEnum;

/**
 * Fakes the Salesforce OAuth2 refresh-token grant and the REST endpoints via Laravel's `Http`
 * facade, so tests exercise the real Client/SalesforceApiClient request-building path without
 * hitting a real Salesforce org.
 */
trait HasSalesforceConfiguration
{
    protected const string SALESFORCE_LOGIN_URL = 'https://login.salesforce.test';
    protected const string SALESFORCE_INSTANCE_URL = 'https://fake.salesforce.test';
    protected const string SALESFORCE_ACCESS_TOKEN = 'test-access-token';

    protected function configureSalesforce(CompanyInterface $company): void
    {
        $company->set(ConfigurationEnum::CLIENT_ID->value, 'test-client-id');
        $company->set(ConfigurationEnum::CLIENT_SECRET->value, 'test-client-secret');
        $company->set(ConfigurationEnum::REFRESH_TOKEN->value, 'test-refresh-token');
        $company->set(ConfigurationEnum::LOGIN_URL->value, self::SALESFORCE_LOGIN_URL);

        // The access token is cached by company id with a TTL — forget it so each test starts
        // from a clean OAuth exchange instead of reusing a token cached by an earlier test.
        Cache::forget('salesforce_token_' . $company->getId());
    }

    protected function fakeSalesforceOAuth(): void
    {
        Http::fake([
            self::SALESFORCE_LOGIN_URL . '/services/oauth2/token' => Http::response([
                'access_token' => self::SALESFORCE_ACCESS_TOKEN,
                'instance_url' => self::SALESFORCE_INSTANCE_URL,
            ], 200),
        ]);
    }
}
