<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Tavily;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Tavily\Enums\ConfigurationEnum;
use Kanvas\Connectors\Tavily\Handlers\TavilyHandler;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Neuron\Tools\Tavily\TavilyCrawlTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Tavily\TavilyExtractTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Tavily\TavilyMapTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Tavily\TavilySearchTool;
use Kanvas\Intelligence\Agents\Services\AgentToolDiscoveryService;
use Kanvas\Regions\Models\Regions;
use Tests\TestCase;

class TavilyToolsTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * The API key lives on the app's settings store, which persists outside the ambient test
     * transaction — save and restore it so these tests never clobber a real configured key.
     */
    private mixed $originalApiKey = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalApiKey = app(Apps::class)->get(ConfigurationEnum::TAVILY_API_KEY->value);
        app(Apps::class)->set(ConfigurationEnum::TAVILY_API_KEY->value, 'tvly-test-key');
    }

    protected function tearDown(): void
    {
        app(Apps::class)->set(ConfigurationEnum::TAVILY_API_KEY->value, $this->originalApiKey);

        parent::tearDown();
    }

    public function test_search_sends_a_bearer_token_and_returns_sources_separately(): void
    {
        [$app, $company] = $this->context();

        Http::fake([
            'api.tavily.com/search' => Http::response([
                'answer' => 'Kanvas is a platform.',
                'results' => [
                    ['title' => 'Kanvas', 'url' => 'https://kanvas.dev', 'content' => 'A platform.', 'score' => 0.9],
                ],
            ], 200),
        ]);

        $result = new TavilySearchTool()
            ->withContext($app, $company, static::$cachedUser)
            ->__invoke(query: 'what is kanvas');

        $this->assertSame('Kanvas is a platform.', $result['answer']);
        $this->assertSame('https://kanvas.dev', $result['results'][0]['url']);

        Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer tvly-test-key')
            && ! array_key_exists('api_key', $request->data()));
    }

    public function test_search_clamps_max_results_to_the_documented_ceiling(): void
    {
        [$app, $company] = $this->context();

        Http::fake([
            'api.tavily.com/search' => Http::response(['answer' => 'ok', 'results' => []], 200),
        ]);

        new TavilySearchTool()
            ->withContext($app, $company, static::$cachedUser)
            ->__invoke(query: 'anything', max_results: 500);

        Http::assertSent(fn (Request $request) => $request->data()['max_results'] === 20);
    }

    public function test_search_returns_a_structured_error_when_the_api_fails(): void
    {
        [$app, $company] = $this->context();

        Http::fake([
            'api.tavily.com/search' => Http::response(['detail' => ['error' => 'Invalid API key']], 401),
        ]);

        $result = new TavilySearchTool()
            ->withContext($app, $company, static::$cachedUser)
            ->__invoke(query: 'anything');

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('Invalid API key', $result['error']);
    }

    public function test_every_tool_reports_a_setup_error_when_no_api_key_is_configured(): void
    {
        [$app, $company] = $this->context();
        $app->set(ConfigurationEnum::TAVILY_API_KEY->value, '');

        Http::fake();

        $results = [
            new TavilySearchTool()->withContext($app, $company, static::$cachedUser)->__invoke(query: 'x'),
            new TavilyExtractTool()->withContext($app, $company, static::$cachedUser)->__invoke(urls: 'https://example.com'),
            new TavilyCrawlTool()->withContext($app, $company, static::$cachedUser)->__invoke(url: 'https://example.com'),
            new TavilyMapTool()->withContext($app, $company, static::$cachedUser)->__invoke(url: 'https://example.com'),
        ];

        foreach ($results as $result) {
            $this->assertArrayHasKey('error', $result);
            $this->assertStringContainsString('not configured', $result['error']);
        }

        Http::assertNothingSent();
    }

    public function test_url_tools_reject_a_malformed_url_before_spending_a_credit(): void
    {
        [$app, $company] = $this->context();

        Http::fake();

        $results = [
            new TavilyExtractTool()->withContext($app, $company, static::$cachedUser)->__invoke(urls: 'not-a-url'),
            new TavilyCrawlTool()->withContext($app, $company, static::$cachedUser)->__invoke(url: 'not-a-url'),
            new TavilyMapTool()->withContext($app, $company, static::$cachedUser)->__invoke(url: 'ftp://example.com'),
        ];

        foreach ($results as $result) {
            $this->assertArrayHasKey('error', $result);
            $this->assertStringContainsString('valid http(s) URL', $result['error']);
        }

        Http::assertNothingSent();
    }

    public function test_read_url_batches_every_url_into_one_call(): void
    {
        [$app, $company] = $this->context();

        Http::fake([
            'api.tavily.com/extract' => Http::response([
                'results' => [
                    ['url' => 'https://example.com/a', 'raw_content' => 'A'],
                    ['url' => 'https://example.com/b', 'raw_content' => 'B'],
                ],
                'failed_results' => [],
            ], 200),
        ]);

        $result = new TavilyExtractTool()
            ->withContext($app, $company, static::$cachedUser)
            ->__invoke(urls: 'https://example.com/a, https://example.com/b');

        $this->assertCount(2, $result['pages']);

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => $request->data()['urls'] === [
            'https://example.com/a',
            'https://example.com/b',
        ]);
    }

    public function test_read_url_keeps_a_comma_inside_a_url_intact(): void
    {
        [$app, $company] = $this->context();

        Http::fake([
            'api.tavily.com/extract' => Http::response(['results' => [['url' => 'x', 'raw_content' => 'y']]], 200),
        ]);

        new TavilyExtractTool()
            ->withContext($app, $company, static::$cachedUser)
            ->__invoke(urls: 'https://maps.example.com/@40.7,-74.0, https://example.com/b');

        Http::assertSent(fn (Request $request) => $request->data()['urls'] === [
            'https://maps.example.com/@40.7,-74.0',
            'https://example.com/b',
        ]);
    }

    public function test_read_url_truncates_a_page_that_would_swamp_the_context_window(): void
    {
        [$app, $company] = $this->context();

        Http::fake([
            'api.tavily.com/extract' => Http::response([
                'results' => [['url' => 'https://example.com', 'raw_content' => str_repeat('x', 50000)]],
            ], 200),
        ]);

        $result = new TavilyExtractTool()
            ->withContext($app, $company, static::$cachedUser)
            ->__invoke(urls: 'https://example.com');

        $this->assertLessThan(50000, mb_strlen($result['pages'][0]['content']));
        $this->assertStringContainsString('truncated', $result['pages'][0]['content']);
    }

    public function test_crawl_clamps_the_page_limit(): void
    {
        [$app, $company] = $this->context();

        Http::fake([
            'api.tavily.com/crawl' => Http::response([
                'base_url' => 'https://example.com',
                'results' => [['url' => 'https://example.com', 'raw_content' => 'home']],
            ], 200),
        ]);

        new TavilyCrawlTool()
            ->withContext($app, $company, static::$cachedUser)
            ->__invoke(url: 'https://example.com', limit: 9999, max_depth: 99);

        Http::assertSent(fn (Request $request) => $request->data()['limit'] === 25
            && $request->data()['max_depth'] === 5);
    }

    public function test_map_returns_only_urls(): void
    {
        [$app, $company] = $this->context();

        Http::fake([
            'api.tavily.com/map' => Http::response([
                'base_url' => 'https://example.com',
                'results' => ['https://example.com/a', 'https://example.com/b'],
            ], 200),
        ]);

        $result = new TavilyMapTool()
            ->withContext($app, $company, static::$cachedUser)
            ->__invoke(url: 'https://example.com');

        $this->assertSame(2, $result['link_count']);
        $this->assertSame(['https://example.com/a', 'https://example.com/b'], $result['links']);
    }

    /**
     * The catalog sync is what puts a tool in the UI picker and in reach of the PM agent's
     * grant/hire tools, so a tool missing from discovery is a tool nobody can be given.
     */
    public function test_all_tavily_tools_are_discoverable_for_the_neuron_runtime(): void
    {
        $discovered = collect(new AgentToolDiscoveryService()->discover())
            ->keyBy('class');

        foreach ([TavilySearchTool::class, TavilyExtractTool::class, TavilyCrawlTool::class, TavilyMapTool::class] as $class) {
            $entry = $discovered->get($class);

            $this->assertNotNull($entry, $class . ' is not in the tool catalog.');
            $this->assertSame('knowledge', $entry['category']);
            $this->assertContains('neuron', $entry['frameworks']);
            $this->assertContains('claude', $entry['frameworks']);
        }
    }

    public function test_handler_stores_the_key_once_tavily_accepts_it(): void
    {
        [$app, $company] = $this->context();
        $app->set(ConfigurationEnum::TAVILY_API_KEY->value, '');

        Http::fake([
            'api.tavily.com/search' => Http::response(['results' => [['url' => 'https://example.com']]], 200),
        ]);

        $this->assertTrue($this->handler($app, $company, ['api_key' => ' tvly-fresh-key '])->setup());
        $this->assertSame('tvly-fresh-key', $app->get(ConfigurationEnum::TAVILY_API_KEY->value));

        Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer tvly-fresh-key'));
    }

    public function test_handler_refuses_a_key_tavily_rejects_and_leaves_the_stored_one_alone(): void
    {
        [$app, $company] = $this->context();

        Http::fake([
            'api.tavily.com/search' => Http::response(['detail' => ['error' => 'Invalid API key']], 401),
        ]);

        try {
            $this->handler($app, $company, ['api_key' => 'tvly-bad'])->setup();
            $this->fail('A rejected key should not set up the integration.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Invalid Tavily API key', $e->getMessage());
        }

        $this->assertSame('tvly-test-key', $app->get(ConfigurationEnum::TAVILY_API_KEY->value));
    }

    public function test_handler_rejects_an_empty_key_without_calling_tavily(): void
    {
        [$app, $company] = $this->context();

        Http::fake();

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Tavily API key is required.');

        try {
            $this->handler($app, $company, ['api_key' => '   '])->setup();
        } finally {
            Http::assertNothingSent();
        }
    }

    /**
     * The integrations row is what makes the handler reachable — the shared integrationCompany
     * mutation resolves `handler` from it, so without the row the setup path does not exist.
     */
    public function test_tavily_is_registered_as_an_integration(): void
    {
        $integration = DB::connection('workflow')
            ->table('integrations')
            ->where('name', 'tavily')
            ->where('apps_id', 0)
            ->first();

        $this->assertNotNull($integration, 'The tavily integrations row is missing — run the migration.');
        $this->assertSame(TavilyHandler::class, $integration->handler);
        $this->assertArrayHasKey('api_key', json_decode((string) $integration->config, true));
    }

    private function handler(Apps $app, Companies $company, array $data): TavilyHandler
    {
        return new TavilyHandler(
            $app,
            $company,
            Regions::getDefault($company, $app),
            $data,
        );
    }

    /**
     * @return array{0: Apps, 1: Companies}
     */
    private function context(): array
    {
        $app = app(Apps::class);
        $company = static::$cachedUser->getCurrentCompany();

        return [$app, $company];
    }
}
