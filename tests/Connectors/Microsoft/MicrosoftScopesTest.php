<?php

declare(strict_types=1);

namespace Tests\Connectors\Microsoft;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Microsoft\Client;
use Kanvas\Connectors\Microsoft\Enums\ConfigurationEnum;
use Kanvas\Connectors\Microsoft\Support\MicrosoftScopes;
use Kanvas\Connectors\Microsoft\Support\MicrosoftTokenStore;
use PHPUnit\Framework\Attributes\Group;
use Tests\Connectors\Microsoft\Concerns\KeepsMicrosoftSettings;
use Tests\TestCase;

/**
 * Serial: it changes app settings (scopes, the app's OneDrive tokens), which every process shares.
 */
#[Group('serial')]
class MicrosoftScopesTest extends TestCase
{
    use DatabaseTransactions;
    use KeepsMicrosoftSettings;

    private Apps $tenantApp;

    private Companies $company;

    private string $suffix;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantApp = app(Apps::class);
        $this->company = static::$cachedUser->getCurrentCompany();
        $this->suffix = ConfigurationEnum::companySuffix($this->tenantApp, $this->company);
        $this->keepMicrosoftSettings($this->tenantApp, $this->company);

        $this->company->set(ConfigurationEnum::CLIENT_ID->value, 'client-id');
        $this->company->set(ConfigurationEnum::CLIENT_SECRET->value, 'client-secret');
        $this->company->set(ConfigurationEnum::TENANT_ID->value, 'tenant-id');
    }

    protected function tearDown(): void
    {
        $this->restoreMicrosoftSettings();

        parent::tearDown();
    }

    public function test_company_authorization_adds_files_and_profile_to_the_mail_default(): void
    {
        $this->tenantApp->del(ConfigurationEnum::SCOPES->value);

        $this->assertSame(
            'offline_access Mail.Read Mail.Send Mail.ReadWrite Files.ReadWrite User.Read',
            MicrosoftScopes::forAuthorization($this->tenantApp)
        );
    }

    public function test_company_authorization_keeps_a_saved_setting_without_duplicates(): void
    {
        $this->tenantApp->set(ConfigurationEnum::SCOPES->value, 'offline_access Mail.Read files.readwrite user.read');

        $this->assertSame('offline_access Mail.Read files.readwrite user.read', MicrosoftScopes::forAuthorization($this->tenantApp));
    }

    public function test_files_access_is_read_from_the_granted_scopes_in_any_case(): void
    {
        $this->assertTrue(MicrosoftScopes::grantsFiles('Mail.Read files.readwrite User.Read'));
        $this->assertFalse(MicrosoftScopes::grantsFiles('Mail.Read Files.Read'));
        $this->assertFalse(MicrosoftScopes::grantsFiles(''));
    }

    public function test_onedrive_connects_with_files_only(): void
    {
        $this->assertSame(
            MicrosoftScopes::ONEDRIVE,
            MicrosoftTokenStore::forAppOneDrive($this->tenantApp)->authorizationScopes()
        );
        $this->assertStringNotContainsString('Mail.', MicrosoftScopes::ONEDRIVE);
    }

    public function test_refresh_uses_mail_scopes_when_nothing_was_stored(): void
    {
        $this->storeExpiredTokens();
        $this->company->del(ConfigurationEnum::GRANTED_SCOPES->value . $this->suffix);
        $this->fakeTokenEndpoint('Mail.Read');

        $this->assertSame('new', Client::getValidAccessToken($this->tenantApp, $this->company));

        Http::assertSent(fn (Request $request) => $request['scope'] === MicrosoftScopes::MAIL);
    }

    public function test_refresh_uses_the_stored_granted_scopes(): void
    {
        $this->storeExpiredTokens();
        $this->company->set(ConfigurationEnum::GRANTED_SCOPES->value . $this->suffix, 'Mail.Read Files.ReadWrite');
        $this->fakeTokenEndpoint('Mail.Read');

        Client::getValidAccessToken($this->tenantApp, $this->company);

        Http::assertSent(fn (Request $request) => $request['scope'] === 'Mail.Read Files.ReadWrite offline_access');
        $this->assertSame(
            'Mail.Read',
            $this->company->get(ConfigurationEnum::GRANTED_SCOPES->value . $this->suffix)
        );
    }

    public function test_store_tokens_writes_every_key_and_keeps_scopes_when_none_were_returned(): void
    {
        Carbon::setTestNow('2026-11-05T10:00:00+00:00');
        $store = MicrosoftTokenStore::forCompanyMail($this->tenantApp, $this->company);
        $prefix = $this->suffix;

        try {
            Client::storeTokens($store, [
                'access_token' => 'a1',
                'refresh_token' => 'r1',
                'expires_in' => 3600,
                'scope' => 'Mail.Read Files.ReadWrite',
            ]);

            $this->assertSame('a1', $this->company->get(ConfigurationEnum::ACCESS_TOKEN->value . $prefix));
            $this->assertSame('r1', $this->company->get(ConfigurationEnum::REFRESH_TOKEN->value . $prefix));
            $this->assertSame(
                '2026-11-05T11:00:00+00:00',
                $this->company->get(ConfigurationEnum::TOKEN_EXPIRES_AT->value . $prefix)
            );
            $this->assertSame('Mail.Read Files.ReadWrite', $this->company->get(ConfigurationEnum::GRANTED_SCOPES->value . $prefix));

            Client::storeTokens($store, [
                'access_token' => 'a2',
                'refresh_token' => 'r2',
                'expires_in' => 60,
                'scope' => '',
            ]);

            $this->assertSame('a2', $this->company->get(ConfigurationEnum::ACCESS_TOKEN->value . $prefix));
            $this->assertSame('Mail.Read Files.ReadWrite', $this->company->get(ConfigurationEnum::GRANTED_SCOPES->value . $prefix));
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_the_app_onedrive_connection_never_touches_a_company_mail_connection(): void
    {
        $this->company->set(ConfigurationEnum::ACCESS_TOKEN->value . $this->suffix, 'company-mail');

        Client::storeTokens(MicrosoftTokenStore::forAppOneDrive($this->tenantApp), [
            'access_token' => 'app-drive',
            'refresh_token' => 'r',
            'expires_in' => 3600,
            'scope' => MicrosoftScopes::ONEDRIVE,
        ]);

        $this->assertSame('app-drive', $this->tenantApp->get(ConfigurationEnum::ACCESS_TOKEN->value . '-onedrive'));
        $this->assertSame('company-mail', $this->company->get(ConfigurationEnum::ACCESS_TOKEN->value . $this->suffix));

        MicrosoftTokenStore::forAppOneDrive($this->tenantApp)->forget();

        $this->assertNull($this->tenantApp->get(ConfigurationEnum::ACCESS_TOKEN->value . '-onedrive'));
        $this->assertSame('company-mail', $this->company->get(ConfigurationEnum::ACCESS_TOKEN->value . $this->suffix));
    }

    public function test_the_app_onedrive_refresh_uses_the_app_registration_not_a_company_override(): void
    {
        $this->tenantApp->set(ConfigurationEnum::CLIENT_ID->value, 'app-client');
        $this->tenantApp->set(ConfigurationEnum::CLIENT_SECRET->value, 'app-secret');
        $this->tenantApp->set(ConfigurationEnum::TENANT_ID->value, 'common');
        $store = MicrosoftTokenStore::forAppOneDrive($this->tenantApp);
        $store->set(ConfigurationEnum::ACCESS_TOKEN, 'old');
        $store->set(ConfigurationEnum::REFRESH_TOKEN, 'r1');
        $store->set(ConfigurationEnum::TOKEN_EXPIRES_AT, Carbon::now()->subHour()->toIso8601String());
        $store->set(ConfigurationEnum::GRANTED_SCOPES, MicrosoftScopes::ONEDRIVE);
        $this->fakeTokenEndpoint(MicrosoftScopes::ONEDRIVE);

        $this->assertSame('new', Client::validAccessToken($store));

        Http::assertSent(fn (Request $request) => $request['client_id'] === 'app-client'
            && str_contains($request->url(), '/common/oauth2/v2.0/token'));
    }

    private function storeExpiredTokens(): void
    {
        $this->company->set(ConfigurationEnum::ACCESS_TOKEN->value . $this->suffix, 'old');
        $this->company->set(ConfigurationEnum::REFRESH_TOKEN->value . $this->suffix, 'r1');
        $this->company->set(
            ConfigurationEnum::TOKEN_EXPIRES_AT->value . $this->suffix,
            Carbon::now()->subHour()->toIso8601String()
        );
    }

    private function fakeTokenEndpoint(string $scope): void
    {
        Http::fake([
            'login.microsoftonline.com/*' => Http::response([
                'access_token' => 'new',
                'refresh_token' => 'r2',
                'expires_in' => 3600,
                'scope' => $scope,
            ]),
        ]);
    }
}
