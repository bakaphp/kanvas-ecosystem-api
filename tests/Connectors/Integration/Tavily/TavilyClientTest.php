<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Tavily;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Tavily\Client;
use Kanvas\Connectors\Tavily\Enums\ConfigurationEnum;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Laravel\Tools\Tavily\TavilyWebResearchTool;
use Laravel\Ai\Tools\Request;
use Tests\TestCase;

final class TavilyClientTest extends TestCase
{
    use DatabaseTransactions;

    private Apps $kanvasApp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->kanvasApp = app(Apps::class);
    }

    public function testClientThrowsWhenApiKeyNotSet(): void
    {
        $blankApp = \Mockery::mock(Apps::class);
        $blankApp->allows('get')->andReturnNull();
        $blankApp->allows('getAttribute')->andReturnNull();
        $blankApp->allows('getId')->andReturn(1);

        $this->expectException(ValidationException::class);

        new Client($blankApp);
    }

    public function testClientInstantiatesWhenKeyIsSet(): void
    {
        $apiKey = env('TAVILY_API_KEY') ?: $this->kanvasApp->get(ConfigurationEnum::TAVILY_API_KEY->value);

        if (empty($apiKey)) {
            $this->markTestSkipped('Tavily API key not configured.');
        }

        $this->kanvasApp->set(ConfigurationEnum::TAVILY_API_KEY->value, $apiKey);
        $this->assertInstanceOf(Client::class, new Client($this->kanvasApp));
    }

    public function testToolHasNameAndDescription(): void
    {
        $tool = new TavilyWebResearchTool();

        $this->assertSame('tavily_web_research', $tool->name());
        $this->assertNotEmpty($tool->description());
        $this->assertNotEmpty($tool->instructions());
    }

    public function testToolReturnsErrorWhenNotConfigured(): void
    {
        $blankApp = \Mockery::mock(Apps::class);
        $blankApp->allows('get')->andReturnNull();
        $blankApp->allows('getAttribute')->andReturnNull();
        $blankApp->allows('getId')->andReturn(1);

        $company = static::$cachedUser->getCurrentCompany();
        $tool = (new TavilyWebResearchTool())->withContext($blankApp, $company);

        $result = json_decode(
            (string) $tool->handle(new Request(['query' => 'Test query about a company'])),
            true
        );

        $this->assertArrayHasKey('error', $result);
    }

    /**
     * Integration test — requires TAVILY_API_KEY env var or app config.
     */
    public function testClientSearchReturnsTheDecodedPayload(): void
    {
        $apiKey = env('TAVILY_API_KEY') ?: $this->kanvasApp->get(ConfigurationEnum::TAVILY_API_KEY->value);

        if (empty($apiKey)) {
            $this->markTestSkipped('Tavily API key not set.');
        }

        $this->kanvasApp->set(ConfigurationEnum::TAVILY_API_KEY->value, $apiKey);

        $client = new Client($this->kanvasApp);
        $result = $client->search(
            'What is the headquarters address of Apple Inc?',
            ['max_results' => 3],
        );

        $this->assertIsArray($result['results']);
        $this->assertNotEmpty($result['results']);
    }

    /**
     * Integration test — requires TAVILY_API_KEY env var or app config.
     */
    public function testToolReturnsResult(): void
    {
        $apiKey = env('TAVILY_API_KEY') ?: $this->kanvasApp->get(ConfigurationEnum::TAVILY_API_KEY->value);

        if (empty($apiKey)) {
            $this->markTestSkipped('Tavily API key not set.');
        }

        $this->kanvasApp->set(ConfigurationEnum::TAVILY_API_KEY->value, $apiKey);

        $company = static::$cachedUser->getCurrentCompany();
        $tool = (new TavilyWebResearchTool())->withContext($this->kanvasApp, $company);

        $result = json_decode(
            (string) $tool->handle(new Request(['query' => 'Who are the major shareholders of Microsoft?'])),
            true
        );

        $this->assertArrayHasKey('results', $result);
        $this->assertNotEmpty($result['results']);
        $this->assertArrayHasKey('url', $result['results'][0]);
    }
}
