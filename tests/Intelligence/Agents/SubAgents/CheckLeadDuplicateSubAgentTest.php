<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents\SubAgents;

use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Laravel\SubAgents\CheckLeadDuplicateSubAgent;
use Kanvas\Intelligence\Agents\Laravel\Tools\Guild\CreateLeadTool;
use Kanvas\Intelligence\Agents\Laravel\Tools\Guild\LeadSearchTool;
use Laravel\Ai\Tools\Request;
use Tests\TestCase;

class CheckLeadDuplicateSubAgentTest extends TestCase
{
    private function app(): Apps
    {
        return app(Apps::class);
    }

    private function makeLeadSearchTool(): LeadSearchTool
    {
        $tool = new LeadSearchTool();
        $tool->withContext($this->app(), auth()->user()->getCurrentCompany());

        return $tool;
    }

    private function makeCreateLeadTool(): CreateLeadTool
    {
        $tool = new CreateLeadTool();
        $tool->withContext($this->app(), auth()->user()->getCurrentCompany());

        return $tool;
    }

    private function makeRequest(array $data): Request
    {
        return new Request($data);
    }

    public function testLeadSearchToolReturnsNoLeadsWhenEmpty(): void
    {
        $tool = $this->makeLeadSearchTool();

        $response = $tool->handle($this->makeRequest([
            'query' => 'xyznonexistentcompany' . uniqid(),
        ]));

        $result = json_decode((string) $response, true);

        $this->assertArrayHasKey('leads', $result);
        $this->assertEmpty($result['leads']);
    }

    public function testLeadSearchToolReturnsCandidates(): void
    {
        $uniqueCompany = 'AcmeCorp' . uniqid();

        $createTool = $this->makeCreateLeadTool();
        $createResponse = json_decode((string) $createTool->handle($this->makeRequest([
            'title' => "{$uniqueCompany} Chapter 11 Bankruptcy",
            'firstname' => $uniqueCompany,
            'description' => "{$uniqueCompany} filed for Chapter 11 with $5B in debt.",
        ])), true);

        $this->assertArrayHasKey('lead_id', $createResponse);

        $searchTool = $this->makeLeadSearchTool();
        $searchResponse = $searchTool->handle($this->makeRequest([
            'query' => $uniqueCompany,
        ]));

        $result = json_decode((string) $searchResponse, true);

        $this->assertNotEmpty($result['leads']);
        $leadIds = array_column($result['leads'], 'id');
        $this->assertContains($createResponse['lead_id'], $leadIds);
    }

    public function testLeadSearchToolSearchesByTitle(): void
    {
        $uniqueTitle = 'UniqueBankruptcyTitle' . uniqid();

        $createTool = $this->makeCreateLeadTool();
        $createResponse = json_decode((string) $createTool->handle($this->makeRequest([
            'title' => $uniqueTitle,
            'firstname' => 'SomeCorp',
        ])), true);

        $searchTool = $this->makeLeadSearchTool();
        $result = json_decode((string) $searchTool->handle($this->makeRequest([
            'query' => $uniqueTitle,
        ])), true);

        $this->assertNotEmpty($result['leads']);
        $leadIds = array_column($result['leads'], 'id');
        $this->assertContains($createResponse['lead_id'], $leadIds);
    }

    public function testSubAgentHasCorrectNameAndDescription(): void
    {
        $subAgent = new CheckLeadDuplicateSubAgent();

        $this->assertSame('check_lead_duplicate', $subAgent->name());
        $this->assertStringContainsString('duplicate', strtolower((string) $subAgent->description()));
        $this->assertStringContainsString('create_lead', strtolower((string) $subAgent->description()));
    }

    public function testContextIsInjectedIntoLeadSearchTool(): void
    {
        $app = $this->app();
        $company = auth()->user()->getCurrentCompany();

        $subAgent = new CheckLeadDuplicateSubAgent();
        $subAgent->withContext($app, $company);

        $tools = $subAgent->tools();

        $this->assertNotEmpty($tools);

        $leadSearchTool = $tools[0] ?? null;
        $this->assertInstanceOf(LeadSearchTool::class, $leadSearchTool);

        // Context is injected — calling handle() should not throw "uninitialized property" errors
        $response = $leadSearchTool->handle($this->makeRequest(['query' => 'test']));
        $this->assertJson((string) $response);
    }

    public function testSubAgentPromptReturnsDuplicateJson(): void
    {
        $app = $this->app();
        $company = auth()->user()->getCurrentCompany();

        $fakeResponse = json_encode([
            'is_duplicate' => true,
            'lead_id' => 999,
            'confidence' => 'high',
            'reason' => 'Same company and event type detected.',
        ]);

        CheckLeadDuplicateSubAgent::fake([$fakeResponse]);

        $subAgent = new CheckLeadDuplicateSubAgent();
        $subAgent->withContext($app, $company);

        $response = $subAgent->prompt('Acme Corp filed for Chapter 11 bankruptcy yesterday with $5B in debt.');
        $result = json_decode((string) $response, true);

        $this->assertTrue($result['is_duplicate']);
        $this->assertSame(999, $result['lead_id']);
        $this->assertSame('high', $result['confidence']);
    }

    public function testSubAgentPromptReturnsFalseWhenNoDuplicate(): void
    {
        $app = $this->app();
        $company = auth()->user()->getCurrentCompany();

        $fakeResponse = json_encode([
            'is_duplicate' => false,
            'lead_id' => null,
            'confidence' => 'high',
            'reason' => 'No matching event found in the database.',
        ]);

        CheckLeadDuplicateSubAgent::fake([$fakeResponse]);

        $subAgent = new CheckLeadDuplicateSubAgent();
        $subAgent->withContext($app, $company);

        $response = $subAgent->prompt('XyzNewCorp announced record profits this quarter.');
        $result = json_decode((string) $response, true);

        $this->assertFalse($result['is_duplicate']);
        $this->assertNull($result['lead_id']);
    }
}
