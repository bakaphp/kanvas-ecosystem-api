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
use Tests\TestCase;

class MicrosoftScopesTest extends TestCase
{
    use DatabaseTransactions;

    private Apps $tenantApp;

    private Companies $company;

    private string $suffix;

    /** @var array<string, mixed> */
    private array $originalCompanyValues = [];

    private mixed $originalScopesSetting = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantApp = app(Apps::class);
        $this->company = static::$cachedUser->getCurrentCompany();
        $this->suffix = '-' . $this->tenantApp->getId() . '-' . $this->company->getId();

        $this->originalScopesSetting = $this->tenantApp->get(ConfigurationEnum::SCOPES->value);

        foreach ($this->companyKeys() as $key) {
            $this->originalCompanyValues[$key] = $this->company->get($key);
        }

        $this->company->set(ConfigurationEnum::CLIENT_ID->value, 'client-id');
        $this->company->set(ConfigurationEnum::CLIENT_SECRET->value, 'client-secret');
        $this->company->set(ConfigurationEnum::TENANT_ID->value, 'tenant-id');
    }

    protected function tearDown(): void
    {
        $this->restore($this->tenantApp, ConfigurationEnum::SCOPES->value, $this->originalScopesSetting);

        foreach ($this->originalCompanyValues as $key => $value) {
            $this->restore($this->company, $key, $value);
        }

        parent::tearDown();
    }

    public function test_authorization_scopes_add_files_to_the_mail_default(): void
    {
        $this->tenantApp->del(ConfigurationEnum::SCOPES->value);

        $this->assertSame(
            'offline_access Mail.Read Mail.Send Mail.ReadWrite Files.ReadWrite User.Read',
            MicrosoftScopes::forAuthorization($this->tenantApp)
        );
    }

    public function test_authorization_scopes_add_files_to_a_saved_mail_only_setting(): void
    {
        $this->tenantApp->set(ConfigurationEnum::SCOPES->value, 'offline_access Mail.Read');

        $this->assertSame(
            'offline_access Mail.Read Files.ReadWrite User.Read',
            MicrosoftScopes::forAuthorization($this->tenantApp)
        );
    }

    public function test_authorization_scopes_do_not_duplicate_files(): void
    {
        $this->tenantApp->set(ConfigurationEnum::SCOPES->value, 'offline_access files.readwrite');

        $scopes = MicrosoftScopes::forAuthorization($this->tenantApp);

        $this->assertSame(1, substr_count(strtolower($scopes), 'files.readwrite'));
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

        try {
            $prefix = $this->suffix;
            Client::storeTokens($this->company, $prefix, [
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

            Client::storeTokens($this->company, $prefix, [
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

    private function restore(Apps|Companies $owner, string $key, mixed $value): void
    {
        $value === null ? $owner->del($key) : $owner->set($key, $value);
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

    /**
     * @return list<string>
     */
    private function companyKeys(): array
    {
        return [
            ConfigurationEnum::CLIENT_ID->value,
            ConfigurationEnum::CLIENT_SECRET->value,
            ConfigurationEnum::TENANT_ID->value,
            ConfigurationEnum::ACCESS_TOKEN->value . $this->suffix,
            ConfigurationEnum::REFRESH_TOKEN->value . $this->suffix,
            ConfigurationEnum::TOKEN_EXPIRES_AT->value . $this->suffix,
            ConfigurationEnum::GRANTED_SCOPES->value . $this->suffix,
        ];
    }
}
