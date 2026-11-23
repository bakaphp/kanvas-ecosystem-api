<?php

declare(strict_types=1);

namespace Tests\Scribe\Delivery;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Kanvas\ActionEngine\Engagements\Models\Engagement;
use Kanvas\Connectors\ShortIo\Enums\ConfigurationEnum;
use Kanvas\Scribe\Delivery\Actions\OpenDocumentEngagementAction;
use Kanvas\Scribe\Delivery\DataTransferObject\DocumentLink;
use Kanvas\Scribe\Delivery\Enums\DocumentActionEnum;
use Kanvas\Scribe\Delivery\Support\DocumentActionsSetup;
use PHPUnit\Framework\Attributes\Group;

/**
 * A document link is the V4 page `{base}/{path}/{engagement uuid}`, shortened through the company's
 * short.io, else the app's, else left long. Serial: the app-level keys live in shared Redis.
 */
#[Group('serial')]
class DocumentLinkShortIoTest extends DocumentDeliveryTestCase
{
    private const array APP_KEYS = [
        ConfigurationEnum::APP_API_KEY,
        ConfigurationEnum::APP_DOMAIN,
        ConfigurationEnum::APP_LINK_PAGE_BASE_URL,
    ];

    /** @var array<string, mixed> */
    private array $originalAppSettings = [];

    protected function afterScribeSetUp(): void
    {
        parent::afterScribeSetUp();

        foreach (self::APP_KEYS as $key) {
            $this->originalAppSettings[$key->value] = $this->kanvasApp->get($key->value);
        }

        $this->company->del(ConfigurationEnum::LINK_PAGE_BASE_URL->value);
        $this->kanvasApp->set(ConfigurationEnum::APP_LINK_PAGE_BASE_URL->value, 'https://app.page');
        $this->kanvasApp->del(ConfigurationEnum::APP_API_KEY->value);
        $this->kanvasApp->del(ConfigurationEnum::APP_DOMAIN->value);
    }

    protected function tearDown(): void
    {
        foreach ($this->originalAppSettings as $key => $value) {
            $value === null ? $this->kanvasApp->del($key) : $this->kanvasApp->set($key, $value);
        }

        foreach (ConfigurationEnum::companyKeys() as $key) {
            $this->company->del($key);
        }

        parent::tearDown();
    }

    public function test_the_company_page_and_short_io_build_the_link(): void
    {
        $this->fakeShortIo();
        $this->company->set(ConfigurationEnum::LINK_PAGE_BASE_URL->value, 'https://docs.acme.com');
        $this->company->setEncrypted(ConfigurationEnum::API_KEY->value, 'company-key');
        $this->company->set(ConfigurationEnum::DOMAIN->value, 'pay.acme.com');
        $this->kanvasApp->set(ConfigurationEnum::APP_API_KEY->value, 'app-key');
        $this->kanvasApp->set(ConfigurationEnum::APP_DOMAIN->value, 'app.link');

        $link = $this->openLink();

        $this->assertSame('https://pay.acme.com/s', $link->link);
        $this->assertLongUrlSent('https://docs.acme.com/view/' . $link->engagement->uuid, 'company-key');
    }

    public function test_without_a_company_integration_the_app_page_and_short_io_build_the_link(): void
    {
        $this->fakeShortIo();
        $this->kanvasApp->set(ConfigurationEnum::APP_API_KEY->value, 'app-key');
        $this->kanvasApp->set(ConfigurationEnum::APP_DOMAIN->value, 'app.link');

        $link = $this->openLink();

        $this->assertSame('https://app.link/s', $link->link);
        $this->assertLongUrlSent('https://app.page/view/' . $link->engagement->uuid, 'app-key');
    }

    public function test_without_any_short_io_the_link_is_the_long_v4_url(): void
    {
        $this->fakeShortIo();
        $this->company->set(ConfigurationEnum::LINK_PAGE_BASE_URL->value, 'https://docs.acme.com/');

        $link = $this->openLink();

        $this->assertSame('https://docs.acme.com/view/' . $link->engagement->uuid, $link->link);
        Http::assertNothingSent();
    }

    public function test_a_short_io_failure_leaves_the_long_v4_url(): void
    {
        Http::fake(['api.short.io/links' => Http::response(['error' => 'down'], 503)]);
        $this->company->setEncrypted(ConfigurationEnum::API_KEY->value, 'company-key');
        $this->company->set(ConfigurationEnum::DOMAIN->value, 'pay.acme.com');

        $link = $this->openLink();

        $this->assertSame('https://app.page/view/' . $link->engagement->uuid, $link->link);
    }

    public function test_every_document_action_takes_the_v4_page_path(): void
    {
        $config = $this->company->get(DocumentActionsSetup::PAGE_CONFIG_KEY);

        foreach (DocumentActionEnum::cases() as $action) {
            $this->assertSame($action->pagePath(), $config[$action->value] ?? null);
        }
    }

    private function fakeShortIo(): void
    {
        Http::fake(['api.short.io/links' => fn (Request $request) => Http::response([
            'shortURL' => 'https://' . $request['domain'] . '/s',
        ])]);
    }

    private function openLink(): DocumentLink
    {
        $link = new OpenDocumentEngagementAction(
            document: $this->acceptedInvoice(),
            people: $this->contact,
            user: Auth::user(),
            action: DocumentActionEnum::VIEW_INVOICE,
        )->execute();

        $this->assertInstanceOf(Engagement::class, $link->engagement);

        return $link;
    }

    private function assertLongUrlSent(string $longUrl, string $apiKey): void
    {
        Http::assertSent(fn (Request $request): bool => $request->url() === ConfigurationEnum::API_URL . '/links'
            && $request['originalURL'] === $longUrl
            && $request->hasHeader('Authorization', $apiKey));
    }
}
