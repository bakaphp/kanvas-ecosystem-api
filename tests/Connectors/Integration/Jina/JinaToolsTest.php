<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Jina;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Jina\Enums\ConfigurationEnum;
use Kanvas\Connectors\Jina\Handlers\JinaHandler;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Neuron\Tools\Jina\JinaReadUrlTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Jina\JinaSearchTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Tavily\TavilyExtractTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Tavily\TavilySearchTool;
use Kanvas\Intelligence\Agents\Services\AgentToolDiscoveryService;
use Kanvas\NervousSystem\Capability\Services\ConnectorReadinessService;
use Kanvas\Regions\Models\Regions;
use Tests\TestCase;

class JinaToolsTest extends TestCase
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

        $this->originalApiKey = app(Apps::class)->get(ConfigurationEnum::JINA_API_KEY->value);
        app(Apps::class)->set(ConfigurationEnum::JINA_API_KEY->value, 'jina-test-key');
    }

    protected function tearDown(): void
    {
        app(Apps::class)->set(ConfigurationEnum::JINA_API_KEY->value, $this->originalApiKey);

        parent::tearDown();
    }

    public function test_read_url_unwraps_the_data_envelope_and_sends_a_bearer_token(): void
    {
        [$app, $company] = $this->context();

        Http::fake([
            'r.jina.ai/*' => Http::response([
                'code' => 200,
                'status' => 20000,
                'data' => [
                    'title' => 'Example Domain',
                    'url' => 'https://example.com/',
                    'content' => 'This domain is for use in documentation examples.',
                ],
            ], 200),
        ]);

        $result = new JinaReadUrlTool()
            ->withContext($app, $company, static::$cachedUser)
            ->__invoke(url: 'https://example.com');

        $this->assertSame('Example Domain', $result['title']);
        $this->assertStringContainsString('documentation examples', $result['content']);

        Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer jina-test-key')
            && $request->data()['url'] === 'https://example.com');
    }

    /**
     * Jina reports failure twice — the HTTP status and an envelope `code`. A 200 carrying an error code
     * must not read downstream as "the page was empty".
     */
    public function test_read_url_treats_an_envelope_error_code_as_a_failure(): void
    {
        [$app, $company] = $this->context();

        Http::fake([
            'r.jina.ai/*' => Http::response([
                'code' => 422,
                'status' => 42200,
                'data' => null,
                'readableMessage' => 'AssertionFailureError: Invalid URL',
            ], 200),
        ]);

        $result = new JinaReadUrlTool()
            ->withContext($app, $company, static::$cachedUser)
            ->__invoke(url: 'https://example.com');

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('Invalid URL', $result['error']);
    }

    public function test_read_url_passes_a_target_selector_as_a_header(): void
    {
        [$app, $company] = $this->context();

        Http::fake([
            'r.jina.ai/*' => Http::response(['code' => 200, 'data' => ['content' => 'body']], 200),
        ]);

        new JinaReadUrlTool()
            ->withContext($app, $company, static::$cachedUser)
            ->__invoke(url: 'https://example.com', target_selector: 'article');

        Http::assertSent(fn (Request $request) => $request->hasHeader('X-Target-Selector', 'article'));
    }

    public function test_read_url_rejects_a_malformed_url_without_calling_jina(): void
    {
        [$app, $company] = $this->context();

        Http::fake();

        $result = new JinaReadUrlTool()
            ->withContext($app, $company, static::$cachedUser)
            ->__invoke(url: 'not-a-url');

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('valid http(s) URL', $result['error']);

        Http::assertNothingSent();
    }

    /**
     * The cheap mode has to be cheap at the API, not after the fact — Jina reads every hit, so trimming
     * content locally would still have paid for it.
     */
    public function test_search_asks_jina_to_omit_content_unless_it_was_requested(): void
    {
        [$app, $company] = $this->context();

        Http::fake([
            's.jina.ai/*' => Http::response([
                'code' => 200,
                'data' => [
                    ['title' => 'A', 'url' => 'https://a.test', 'description' => 'd', 'content' => 'full text'],
                ],
            ], 200),
        ]);

        $lean = new JinaSearchTool()
            ->withContext($app, $company, static::$cachedUser)
            ->__invoke(query: 'anything');

        $this->assertArrayNotHasKey('content', $lean['results'][0]);
        Http::assertSent(fn (Request $request) => $request->hasHeader('X-Respond-With', 'no-content'));

        $full = new JinaSearchTool()
            ->withContext($app, $company, static::$cachedUser)
            ->__invoke(query: 'anything', include_content: true);

        $this->assertSame('full text', $full['results'][0]['content']);
    }

    public function test_both_tools_report_a_setup_error_when_no_api_key_is_configured(): void
    {
        [$app, $company] = $this->context();
        $app->set(ConfigurationEnum::JINA_API_KEY->value, '');

        Http::fake();

        $results = [
            new JinaReadUrlTool()->withContext($app, $company, static::$cachedUser)->__invoke(url: 'https://example.com'),
            new JinaSearchTool()->withContext($app, $company, static::$cachedUser)->__invoke(query: 'x'),
        ];

        foreach ($results as $result) {
            $this->assertArrayHasKey('error', $result);
            $this->assertStringContainsString('not configured', $result['error']);
        }

        Http::assertNothingSent();
    }

    public function test_handler_stores_the_key_once_jina_accepts_it(): void
    {
        [$app, $company] = $this->context();
        $app->set(ConfigurationEnum::JINA_API_KEY->value, '');

        Http::fake(['s.jina.ai/*' => Http::response(['code' => 200, 'data' => []], 200)]);

        $this->assertTrue($this->handler($app, $company, ['api_key' => ' jina-fresh '])->setup());
        $this->assertSame('jina-fresh', $app->get(ConfigurationEnum::JINA_API_KEY->value));
    }

    public function test_handler_refuses_a_key_jina_rejects(): void
    {
        [$app, $company] = $this->context();

        Http::fake([
            's.jina.ai/*' => Http::response(['code' => 401, 'name' => 'AuthenticationRequiredError'], 401),
        ]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Invalid Jina API key');

        $this->handler($app, $company, ['api_key' => 'jina-bad'])->setup();
    }

    public function test_jina_is_registered_as_an_integration(): void
    {
        $integration = DB::connection('workflow')
            ->table('integrations')
            ->where('name', 'jina')
            ->where('apps_id', 0)
            ->first();

        $this->assertNotNull($integration, 'The jina integrations row is missing — run the migration.');
        $this->assertSame(JinaHandler::class, $integration->handler);
        $this->assertArrayHasKey('api_key', json_decode((string) $integration->config, true));
    }

    public function test_readiness_reports_jina_per_tool_handler(): void
    {
        $app = app(Apps::class);
        $readiness = new ConnectorReadinessService();

        $this->assertTrue($readiness->forHandler(JinaReadUrlTool::class, $app)?->ready);

        $app->set(ConfigurationEnum::JINA_API_KEY->value, '');
        $this->assertFalse($readiness->forHandler(JinaSearchTool::class, $app)?->ready);
    }

    public function test_jina_tools_are_in_the_catalog_and_do_not_collide_with_tavily(): void
    {
        $discovered = collect(new AgentToolDiscoveryService()->discover())->keyBy('class');

        foreach ([JinaReadUrlTool::class, JinaSearchTool::class] as $class) {
            $entry = $discovered->get($class);

            $this->assertNotNull($entry, $class . ' is not in the tool catalog.');
            $this->assertSame('knowledge', $entry['category']);
            $this->assertContains('neuron', $entry['frameworks']);
        }

        // An agent may hold both connectors, and two tools answering to one name breaks the whole turn.
        $names = array_map(
            static fn (string $class): string => new $class()->getName(),
            [JinaReadUrlTool::class, JinaSearchTool::class, TavilyExtractTool::class, TavilySearchTool::class],
        );

        $this->assertSame($names, array_unique($names));
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

    private function handler(Apps $app, Companies $company, array $data): JinaHandler
    {
        return new JinaHandler(
            $app,
            $company,
            Regions::getDefault($company, $app),
            $data,
        );
    }
}
