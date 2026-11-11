<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\ShortIo;

use Baka\Support\Url;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\ShortIo\Enums\ConfigurationEnum;
use Kanvas\Connectors\ShortIo\Handlers\ShortIoHandler;
use Kanvas\Connectors\ShortIo\Services\ShortIoConfigurationService;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Regions\Models\Regions;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Serial: the app-level fallback keys live in Redis, shared by every paratest process.
 */
#[Group('serial')]
final class ShortIoHandlerTest extends TestCase
{
    private const array APP_KEYS = [
        ConfigurationEnum::APP_API_KEY,
        ConfigurationEnum::APP_DOMAIN,
        ConfigurationEnum::APP_LINK_PAGE_BASE_URL,
    ];

    private Apps $kanvasApp;
    private Companies $company;

    /** @var array<string, mixed> */
    private array $originalAppSettings = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->kanvasApp = app(Apps::class);
        $this->company = auth()->user()->getCurrentCompany();

        foreach (self::APP_KEYS as $key) {
            $this->originalAppSettings[$key->value] = $this->kanvasApp->get($key->value);
            $this->kanvasApp->del($key->value);
        }

        $this->clearCompanyKeys();
    }

    protected function tearDown(): void
    {
        $this->clearCompanyKeys();

        foreach ($this->originalAppSettings as $key => $value) {
            $value === null ? $this->kanvasApp->del($key) : $this->kanvasApp->set($key, $value);
        }

        parent::tearDown();
    }

    public function test_setup_validates_the_domain_against_the_account_and_stores_the_key_encrypted(): void
    {
        Http::fake(['api.short.io/api/domains*' => Http::response([
            ['id' => 1, 'hostname' => 'pay.acme.com', 'unicodeHostname' => 'pay.acme.com'],
        ])]);

        $this->assertTrue($this->handler([
            'api_key' => ' sk_live_123 ',
            'domain' => 'https://Pay.Acme.com/',
            'link_page_base_url' => 'https://docs.acme.com/',
        ])->setup());

        $this->assertTrue($this->company->isSecret(ConfigurationEnum::API_KEY->value));
        $this->assertSame('sk_live_123', $this->company->get(ConfigurationEnum::API_KEY->value));
        $this->assertSame('pay.acme.com', $this->company->get(ConfigurationEnum::DOMAIN->value));
        $this->assertSame('https://docs.acme.com', $this->company->get(ConfigurationEnum::LINK_PAGE_BASE_URL->value));

        Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'sk_live_123'));
    }

    public function test_setup_rejects_a_domain_the_account_does_not_own_and_stores_nothing(): void
    {
        Http::fake(['api.short.io/api/domains*' => Http::response([['id' => 1, 'hostname' => 'other.io']])]);

        $this->expectSetupFailure(['api_key' => 'sk', 'domain' => 'pay.acme.com'], 'not a domain of this short.io account');
    }

    public function test_setup_rejects_a_key_short_io_refuses(): void
    {
        Http::fake(['api.short.io/api/domains*' => Http::response(['error' => 'Unauthorized'], 401)]);

        $this->expectSetupFailure(['api_key' => 'bad', 'domain' => 'pay.acme.com'], 'rejected the API key');
    }

    public function test_setup_rejects_a_link_page_base_url_that_is_not_plain_https(): void
    {
        Http::fake();

        foreach (['http://docs.acme.com', 'https://docs.acme.com/?a=1', 'docs.acme.com'] as $url) {
            $this->expectSetupFailure(
                ['api_key' => 'sk', 'domain' => 'pay.acme.com', 'link_page_base_url' => $url],
                'https:// address'
            );
        }

        Http::assertNothingSent();
    }

    public function test_teardown_clears_every_company_key(): void
    {
        $this->setCompanyIntegration();

        $this->handler([])->teardown();

        foreach (ConfigurationEnum::companyKeys() as $key) {
            $this->assertNull($this->company->get($key));
        }
    }

    public function test_short_links_resolve_company_then_app_then_the_long_url(): void
    {
        Http::fake(['api.short.io/links' => fn (Request $request) => Http::response([
            'shortURL' => 'https://' . $request['domain'] . '/x',
        ])]);
        $long = 'https://docs.acme.com/invoice/abc';

        $this->assertSame($long, Url::getShortUrl($long, $this->kanvasApp, company: $this->company));

        $this->kanvasApp->set(ConfigurationEnum::APP_API_KEY->value, 'app-key');
        $this->kanvasApp->set(ConfigurationEnum::APP_DOMAIN->value, 'app.link');
        $this->assertSame('https://app.link/x', Url::getShortUrl($long, $this->kanvasApp, company: $this->company));

        $this->setCompanyIntegration();
        $this->assertSame('https://pay.acme.com/x', Url::getShortUrl($long, $this->kanvasApp, company: $this->company));
        $this->assertSame('https://app.link/x', Url::getShortUrl($long, $this->kanvasApp));

        Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'company-key')
            && $request['originalURL'] === $long);
    }

    public function test_a_short_io_failure_returns_the_long_url(): void
    {
        Http::fake(['api.short.io/links' => Http::response(['error' => 'boom'], 500)]);
        $this->setCompanyIntegration();

        $this->assertSame('https://docs.acme.com/x', Url::getShortUrl('https://docs.acme.com/x', $this->kanvasApp, company: $this->company));
    }

    public function test_link_page_base_url_resolves_company_then_app(): void
    {
        $this->kanvasApp->set(ConfigurationEnum::APP_LINK_PAGE_BASE_URL->value, 'https://app.page/');
        $this->assertSame('https://app.page', ShortIoConfigurationService::linkPageBaseUrl($this->kanvasApp, $this->company));

        $this->company->set(ConfigurationEnum::LINK_PAGE_BASE_URL->value, 'https://docs.acme.com');
        $this->assertSame('https://docs.acme.com', ShortIoConfigurationService::linkPageBaseUrl($this->kanvasApp, $this->company));
        $this->assertSame('https://app.page', ShortIoConfigurationService::linkPageBaseUrl($this->kanvasApp));
    }

    public function test_the_integration_row_points_at_the_handler(): void
    {
        $row = DB::connection('workflow')->table('integrations')
            ->where('name', IntegrationsEnum::SHORTIO->value)
            ->where('apps_id', 0)
            ->first();

        $this->assertNotNull($row);
        $this->assertSame(ShortIoHandler::class, $row->handler);
        $this->assertSame(['api_key', 'domain', 'link_page_base_url'], array_keys(json_decode($row->config, true)));
    }

    private function expectSetupFailure(array $data, string $message): void
    {
        try {
            $this->handler($data)->setup();
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString($message, $e->getMessage());
        }

        $this->assertNull($this->company->get(ConfigurationEnum::API_KEY->value));
    }

    private function setCompanyIntegration(): void
    {
        $this->company->setEncrypted(ConfigurationEnum::API_KEY->value, 'company-key');
        $this->company->set(ConfigurationEnum::DOMAIN->value, 'pay.acme.com');
        $this->company->set(ConfigurationEnum::LINK_PAGE_BASE_URL->value, 'https://docs.acme.com');
    }

    private function clearCompanyKeys(): void
    {
        foreach (ConfigurationEnum::companyKeys() as $key) {
            $this->company->del($key);
        }
    }

    private function handler(array $data): ShortIoHandler
    {
        return new ShortIoHandler(
            $this->kanvasApp,
            $this->company,
            Regions::getDefault($this->company, $this->kanvasApp),
            $data,
        );
    }
}
