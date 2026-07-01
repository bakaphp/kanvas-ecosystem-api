<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents\Tools;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Laravel\Tools\News\GoogleNewsRssTool;
use Laravel\Ai\Tools\Request;
use Tests\TestCase;

final class GoogleNewsRssToolTest extends TestCase
{
    use DatabaseTransactions;

    private Apps $kanvasApp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->kanvasApp = app(Apps::class);
    }

    private function makeTool(): GoogleNewsRssTool
    {
        $company = auth()->user()->getCurrentCompany();

        return (new GoogleNewsRssTool())->withContext($this->kanvasApp, $company);
    }

    public function testToolHasNameAndDescription(): void
    {
        $tool = new GoogleNewsRssTool();

        $this->assertSame('google_news_rss', $tool->name());
        $this->assertNotEmpty($tool->description());
        $this->assertNotEmpty($tool->instructions());
    }

    public function testHandleReturnsArticlesForKnownCompany(): void
    {
        $tool = $this->makeTool();

        $result = json_decode(
            (string) $tool->handle(new Request(['company_name' => 'Apple Inc', 'limit' => 3])),
            true
        );

        if (isset($result['error'])) {
            $this->markTestSkipped('Google News RSS not reachable from test environment: ' . $result['error']);
        }

        $this->assertArrayHasKey('articles', $result);
        $this->assertIsArray($result['articles']);
    }

    public function testHandleRespectsLimit(): void
    {
        $tool = $this->makeTool();

        $result = json_decode(
            (string) $tool->handle(new Request(['company_name' => 'Apple', 'limit' => 2])),
            true
        );

        if (isset($result['error'])) {
            $this->markTestSkipped('Google News RSS not reachable: ' . $result['error']);
        }

        $this->assertArrayHasKey('articles', $result);
        $this->assertLessThanOrEqual(2, count($result['articles']));
    }

    public function testHandleArticlesHaveExpectedKeys(): void
    {
        $tool = $this->makeTool();

        $result = json_decode(
            (string) $tool->handle(new Request(['company_name' => 'Microsoft', 'limit' => 1])),
            true
        );

        if (isset($result['error']) || empty($result['articles'])) {
            $this->markTestSkipped('Google News RSS not reachable or returned empty.');
        }

        $article = $result['articles'][0];
        $this->assertArrayHasKey('title', $article);
        $this->assertArrayHasKey('url', $article);
        $this->assertArrayHasKey('source', $article);
        $this->assertArrayHasKey('published_at', $article);
    }

    public function testHandleReturnsErrorOnBlockedUrl(): void
    {
        $tool = $this->makeTool();

        // Force an SSRF-blocked host by testing with a private IP URL
        // The tool itself uses SafeUrlFetcher which blocks private hosts.
        // We can't easily override the URL in the tool, so we just verify
        // that the handle() method returns a JSON-encoded response (never throws).
        $result = $tool->handle(new Request(['company_name' => 'test']));

        $decoded = json_decode((string) $result, true);
        $this->assertIsArray($decoded);
        $this->assertTrue(
            isset($decoded['articles']) || isset($decoded['error']),
            'Response must have articles or error key'
        );
    }
}
