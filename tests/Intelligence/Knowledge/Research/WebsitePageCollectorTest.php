<?php

declare(strict_types=1);

namespace Tests\Intelligence\Knowledge\Research;

use Baka\Http\Exceptions\SsrfException;
use Baka\Http\SafeUrlFetcher;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Jina\Enums\ConfigurationEnum as JinaConfigurationEnum;
use Kanvas\Connectors\Tavily\Enums\ConfigurationEnum as TavilyConfigurationEnum;
use Kanvas\Intelligence\Knowledge\Research\Enums\PublicEmailDomainEnum;
use Kanvas\Intelligence\Knowledge\Research\Services\WebsitePageCollector;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;
use Tests\Traits\ManagesAppSettings;

/**
 * A literal public IP stands in for the company website: SafeUrl::assertSafe resolves DNS, so a
 * made-up domain would be refused before any faked HTTP call is reached.
 */
#[Group('serial')]
final class WebsitePageCollectorTest extends TestCase
{
    use DatabaseTransactions;
    use ManagesAppSettings;

    private const string HOME = 'https://93.184.216.34';

    protected function setUp(): void
    {
        parent::setUp();

        $this->rememberAppSettings(TavilyConfigurationEnum::TAVILY_API_KEY->value, JinaConfigurationEnum::JINA_API_KEY->value);
    }

    public function testTavilyPathKeepsHomePlusRankedPagesCappedAtTwelve(): void
    {
        $this->useTavily();
        $mapped = [self::HOME . '/', self::HOME . '/about', self::HOME . '/pricing', 'https://other.example/about'];
        foreach (range(1, 20) as $i) {
            $mapped[] = self::HOME . '/blog/' . $i;
        }

        Http::fake([
            'api.tavily.com/map' => Http::response(['base_url' => self::HOME, 'results' => $mapped]),
            'api.tavily.com/extract' => function (Request $request) {
                return Http::response(['results' => array_map(
                    static fn (string $url): array => ['url' => $url, 'raw_content' => 'Content of ' . $url],
                    $request['urls'],
                )]);
            },
        ]);

        $pages = new WebsitePageCollector(app(Apps::class))->collect('93.184.216.34');

        $this->assertSame('tavily', $pages->collector);
        $this->assertCount(12, $pages->pages);
        $this->assertSame(self::HOME, $pages->pages[0]['url']);

        Http::assertSent(function (Request $request): bool {
            if (! str_ends_with($request->url(), '/extract')) {
                return false;
            }
            $urls = $request['urls'];

            return count($urls) === 12
                && in_array(self::HOME . '/about', $urls, true)
                && in_array(self::HOME . '/pricing', $urls, true)
                && array_all($urls, static fn (string $url): bool => str_starts_with($url, self::HOME));
        });
    }

    public function testContentIsTruncatedToSixThousandCharacters(): void
    {
        $this->useTavily();
        Http::fake([
            'api.tavily.com/map' => Http::response(['results' => [self::HOME]]),
            'api.tavily.com/extract' => Http::response(['results' => [
                ['url' => self::HOME, 'raw_content' => str_repeat('a', 10_000)],
            ]]),
        ]);

        $pages = new WebsitePageCollector(app(Apps::class))->collect(self::HOME);

        $this->assertCount(1, $pages->pages);
        $this->assertSame(6_000, mb_strlen($pages->pages[0]['content']));
    }

    public function testFallsBackToJinaWhenTavilyKeyMissing(): void
    {
        app(Apps::class)->del(TavilyConfigurationEnum::TAVILY_API_KEY->value);
        app(Apps::class)->set(JinaConfigurationEnum::JINA_API_KEY->value, 'jina-test-key');
        Http::fake([
            'r.jina.ai/*' => Http::response([
                'code' => 200,
                'status' => 20000,
                'data' => ['title' => 'Acme Co', 'url' => self::HOME, 'content' => '# Acme Co\n\nWe sell furniture.'],
            ]),
        ]);

        $pages = new WebsitePageCollector(app(Apps::class))->collect(self::HOME);

        $this->assertSame('jina', $pages->collector);
        $this->assertCount(1, $pages->pages);
        $this->assertSame('Acme Co', $pages->pages[0]['title']);
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'tavily'));
    }

    public function testFallsBackToDirectFetchWhenNoVendorKey(): void
    {
        app(Apps::class)->del(TavilyConfigurationEnum::TAVILY_API_KEY->value);
        app(Apps::class)->del(JinaConfigurationEnum::JINA_API_KEY->value);
        Http::fake();
        SafeUrlFetcher::fake(static fn (string $url): string => '<html><head><title>Acme Co</title></head>'
            . '<body><h1>Welcome</h1><p>We sell furniture.</p><script>var x = 1;</script></body></html>');

        $pages = new WebsitePageCollector(app(Apps::class))->collect(self::HOME);

        $this->assertSame('direct', $pages->collector);
        $this->assertCount(1, $pages->pages);
        $this->assertSame('Acme Co', $pages->pages[0]['title']);
        $this->assertStringNotContainsString('<', $pages->pages[0]['content']);
        $this->assertStringContainsString('We sell furniture.', $pages->pages[0]['content']);
        Http::assertNothingSent();
    }

    public function testRefusesPrivateHostBeforeAnyRequest(): void
    {
        $this->useTavily();
        Http::fake();

        try {
            new WebsitePageCollector(app(Apps::class))->collect('http://10.0.0.1');
            $this->fail('A private host must be refused');
        } catch (SsrfException) {
        }

        Http::assertNothingSent();
    }

    public function testPublicEmailDomainEnumKnowsGmail(): void
    {
        $this->assertTrue(PublicEmailDomainEnum::isPublic('gmail.com'));
        $this->assertTrue(PublicEmailDomainEnum::isPublic('GMAIL.com'));
        $this->assertFalse(PublicEmailDomainEnum::isPublic('kanvas.dev'));
        $this->assertContains('yopmail.com', PublicEmailDomainEnum::list());
    }

    private function useTavily(): void
    {
        app(Apps::class)->set(TavilyConfigurationEnum::TAVILY_API_KEY->value, 'tvly-test-key');
    }
}
