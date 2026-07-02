<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents\Tools;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Apollo\Enums\ConfigurationEnum as ApolloConfigEnum;
use Kanvas\Intelligence\Agents\Laravel\Tools\Apollo\ApolloLinkedInLookupTool;
use Laravel\Ai\Tools\Request;
use Tests\TestCase;

final class ApolloLinkedInLookupToolTest extends TestCase
{
    use DatabaseTransactions;

    private Apps $kanvasApp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->kanvasApp = app(Apps::class);
    }

    private function makeTool(): ApolloLinkedInLookupTool
    {
        $company = static::$cachedUser->getCurrentCompany();

        return (new ApolloLinkedInLookupTool())->withContext($this->kanvasApp, $company);
    }

    public function testToolHasNameAndDescription(): void
    {
        $tool = new ApolloLinkedInLookupTool();

        $this->assertSame('apollo_linkedin_lookup', $tool->name());
        $this->assertNotEmpty($tool->description());
        $this->assertNotEmpty($tool->instructions());
    }

    public function testHandleReturnsEmptyArrayWhenNoPeopleProvided(): void
    {
        $tool = $this->makeTool();

        $result = json_decode(
            (string) $tool->handle(new Request(['company_name' => 'Apple', 'people' => []])),
            true
        );

        $this->assertArrayHasKey('people', $result);
        $this->assertSame([], $result['people']);
    }

    public function testHandleReturnsErrorWhenApolloNotConfigured(): void
    {
        $blankApp = \Mockery::mock(Apps::class);
        $blankApp->allows('get')->andReturnNull();
        $blankApp->allows('getAttribute')->andReturnNull();

        $company = static::$cachedUser->getCurrentCompany();
        $tool = (new ApolloLinkedInLookupTool())->withContext($blankApp, $company);

        $result = json_decode(
            (string) $tool->handle(new Request([
                'company_name' => 'Apple',
                'people' => [['name' => 'Tim Cook', 'title' => 'CEO']],
            ])),
            true
        );

        $this->assertArrayHasKey('error', $result);
    }

    public function testHandleSkipsPeopleWithNoName(): void
    {
        $apiKey = getenv('TEST_APOLLO_API_KEY') ?: $this->kanvasApp->get(ApolloConfigEnum::APOLLO_API_KEY->value);

        if (empty($apiKey)) {
            $this->markTestSkipped('Apollo API key not configured.');
        }

        $tool = $this->makeTool();

        $result = json_decode(
            (string) $tool->handle(new Request([
                'company_name' => 'Apple',
                'people' => [['name' => '', 'title' => 'CEO']],
            ])),
            true
        );

        $this->assertArrayHasKey('people', $result);
        $this->assertSame([], $result['people']);
    }

    /**
     * Integration test — requires Apollo API key to be set on the app.
     */
    public function testHandleReturnsLinkedInUrlForHighConfidenceMatch(): void
    {
        $apiKey = $this->kanvasApp->get(ApolloConfigEnum::APOLLO_API_KEY->value);

        if (empty($apiKey)) {
            $this->markTestSkipped('Apollo API key not set on the app.');
        }

        $tool = $this->makeTool();

        $result = json_decode(
            (string) $tool->handle(new Request([
                'company_name' => 'Microsoft',
                'people' => [['name' => 'Satya Nadella', 'title' => 'CEO']],
            ])),
            true
        );

        $this->assertArrayHasKey('people', $result);
        $this->assertIsArray($result['people']);

        if (! empty($result['people'])) {
            $person = $result['people'][0];
            $this->assertArrayHasKey('linkedin_url', $person);
            $this->assertArrayHasKey('confidence', $person);
            $this->assertGreaterThan(0.5, $person['confidence']);
        }
    }
}
