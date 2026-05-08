<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents\Tools;

use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Laravel\Tools\Guild\AddLeadTagsTool;
use Kanvas\Intelligence\Agents\Laravel\Tools\Guild\CreateLeadTool;
use Kanvas\Intelligence\Agents\Laravel\Tools\Guild\SetLeadCustomFieldsTool;
use Laravel\Ai\Tools\Request;
use Tests\TestCase;

class GuildToolsTest extends TestCase
{
    private function makeTool(string $class): CreateLeadTool|SetLeadCustomFieldsTool|AddLeadTagsTool
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        /** @var CreateLeadTool|SetLeadCustomFieldsTool|AddLeadTagsTool $tool */
        $tool = new $class();
        $tool->withContext($app, $company);

        return $tool;
    }

    private function makeRequest(array $data): Request
    {
        return new Request($data);
    }

    public function testCreateLeadToolCreatesLeadAndReturnsId(): void
    {
        /** @var CreateLeadTool $tool */
        $tool = $this->makeTool(CreateLeadTool::class);

        $response = $tool->handle($this->makeRequest([
            'title' => 'Acme Corp — Chapter 11',
            'firstname' => 'Acme Corp',
            'description' => 'Filed for Chapter 11 with $5B in debt.',
        ]));

        $result = json_decode((string) $response, true);

        $this->assertArrayHasKey('lead_id', $result);
        $this->assertIsInt($result['lead_id']);
        $this->assertGreaterThan(0, $result['lead_id']);
        $this->assertSame('Acme Corp — Chapter 11', $result['title']);
    }

    public function testCreateLeadToolWithEmailAndPhone(): void
    {
        /** @var CreateLeadTool $tool */
        $tool = $this->makeTool(CreateLeadTool::class);

        $response = $tool->handle($this->makeRequest([
            'title' => 'Contact Test Lead',
            'firstname' => 'John',
            'lastname' => 'Doe',
            'email' => 'john@example.com',
            'phone' => '+15551234567',
        ]));

        $result = json_decode((string) $response, true);

        $this->assertArrayHasKey('lead_id', $result);
        $lead = Lead::find($result['lead_id']);
        $this->assertNotNull($lead);
        $this->assertSame('john@example.com', $lead->email);
    }

    public function testSetLeadCustomFieldsToolSetsFields(): void
    {
        /** @var CreateLeadTool $createTool */
        $createTool = $this->makeTool(CreateLeadTool::class);

        $createResponse = json_decode((string) $createTool->handle($this->makeRequest([
            'title' => 'Custom Fields Test Lead',
            'firstname' => 'Test Company',
        ])), true);

        $leadId = $createResponse['lead_id'];

        /** @var SetLeadCustomFieldsTool $setTool */
        $setTool = $this->makeTool(SetLeadCustomFieldsTool::class);

        $response = $setTool->handle($this->makeRequest([
            'lead_id' => $leadId,
            'fields' => [
                'event_type' => 'Chapter11Bankruptcy',
                'severity' => '4',
            ],
        ]));

        $result = json_decode((string) $response, true);

        $this->assertTrue($result['success']);
        $this->assertSame($leadId, $result['lead_id']);
        $this->assertSame(2, $result['fields_set']);

        $lead = Lead::find($leadId);
        $this->assertSame('Chapter11Bankruptcy', $lead->get('event_type'));
        $this->assertEquals('4', $lead->get('severity'));
    }

    public function testSetLeadCustomFieldsToolReturnsErrorForUnknownLead(): void
    {
        /** @var SetLeadCustomFieldsTool $tool */
        $tool = $this->makeTool(SetLeadCustomFieldsTool::class);

        $response = (string) $tool->handle($this->makeRequest([
            'lead_id' => 999999999,
            'fields' => ['event_type' => 'test'],
        ]));

        $this->assertStringContainsString('not found', $response);
    }

    public function testSetLeadCustomFieldsToolReturnsErrorWhenFieldsEmpty(): void
    {
        /** @var SetLeadCustomFieldsTool $tool */
        $tool = $this->makeTool(SetLeadCustomFieldsTool::class);

        $response = (string) $tool->handle($this->makeRequest([
            'lead_id' => 1,
            'fields' => [],
        ]));

        $this->assertStringContainsString('No fields provided', $response);
    }

    public function testAddLeadTagsToolAddsTags(): void
    {
        /** @var CreateLeadTool $createTool */
        $createTool = $this->makeTool(CreateLeadTool::class);
        $createResponse = json_decode((string) $createTool->handle($this->makeRequest([
            'title' => 'Tags Test Lead',
            'firstname' => 'Tags Corp',
        ])), true);
        $leadId = $createResponse['lead_id'];

        /** @var AddLeadTagsTool $tagTool */
        $tagTool = $this->makeTool(AddLeadTagsTool::class);
        $response = $tagTool->handle($this->makeRequest([
            'lead_id' => $leadId,
            'tags' => ['Chapter11Bankruptcy', 'high-severity', 'auto-created'],
        ]));

        $result = json_decode((string) $response, true);

        $this->assertTrue($result['success']);
        $this->assertSame($leadId, $result['lead_id']);
        $this->assertSame(3, $result['tags_added']);

        $lead = Lead::find($leadId);
        $this->assertTrue($lead->hasTag(['Chapter11Bankruptcy']));
        $this->assertTrue($lead->hasTag(['high-severity']));
    }

    public function testAddLeadTagsToolCreatesTagsIfNotExist(): void
    {
        /** @var CreateLeadTool $createTool */
        $createTool = $this->makeTool(CreateLeadTool::class);
        $createResponse = json_decode((string) $createTool->handle($this->makeRequest([
            'title' => 'New Tag Lead',
            'firstname' => 'NewTag Corp',
        ])), true);
        $leadId = $createResponse['lead_id'];

        $uniqueTag = 'unique-tag-' . uniqid();

        /** @var AddLeadTagsTool $tagTool */
        $tagTool = $this->makeTool(AddLeadTagsTool::class);
        $tagTool->handle($this->makeRequest([
            'lead_id' => $leadId,
            'tags' => [$uniqueTag],
        ]));

        $lead = Lead::find($leadId);
        $this->assertTrue($lead->hasTag([$uniqueTag]));
    }

    public function testAddLeadTagsToolReturnsErrorForUnknownLead(): void
    {
        /** @var AddLeadTagsTool $tool */
        $tool = $this->makeTool(AddLeadTagsTool::class);

        $response = (string) $tool->handle($this->makeRequest([
            'lead_id' => 999999999,
            'tags' => ['test-tag'],
        ]));

        $this->assertStringContainsString('not found', $response);
    }

    public function testAddLeadTagsToolReturnsErrorWhenTagsEmpty(): void
    {
        /** @var AddLeadTagsTool $tool */
        $tool = $this->makeTool(AddLeadTagsTool::class);

        $response = (string) $tool->handle($this->makeRequest([
            'lead_id' => 1,
            'tags' => [],
        ]));

        $this->assertStringContainsString('No tags provided', $response);
    }
}
