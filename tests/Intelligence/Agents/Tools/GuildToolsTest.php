<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents\Tools;

use Illuminate\Support\Facades\Notification;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Intelligence\Agents\Laravel\Tools\Guild\AddLeadTagsTool;
use Kanvas\Intelligence\Agents\Laravel\Tools\Guild\CreateLeadTool;
use Kanvas\Intelligence\Agents\Laravel\Tools\Guild\GetOrganizationCustomFieldsTool;
use Kanvas\Intelligence\Agents\Laravel\Tools\Guild\HandOffLeadTool;
use Kanvas\Intelligence\Agents\Laravel\Tools\Guild\SetLeadCustomFieldsTool;
use Kanvas\Intelligence\Agents\Laravel\Tools\Guild\SetOrganizationCustomFieldsTool;
use Kanvas\Intelligence\Enums\ConfigurationEnum;
use Laravel\Ai\Tools\Request;
use Tests\TestCase;

class GuildToolsTest extends TestCase
{
    private Apps $kanvasApp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->kanvasApp = app(Apps::class);
    }

    private function makeTool(string $class): object
    {
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $tool = new $class();
        $tool->withContext($this->kanvasApp, $company);

        return $tool;
    }

    private function seedOrganization(string $name): Organization
    {
        $user = auth()->user();

        return Organization::create([
            'apps_id' => $this->kanvasApp->getId(),
            'companies_id' => $user->getCurrentCompany()->getId(),
            'users_id' => $user->getId(),
            'name' => $name,
            'address' => '',
            'total_employees' => 0,
        ]);
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

    public function testHandOffLeadToolExecutesPromptSelectedType(): void
    {
        Notification::fake();

        /** @var CreateLeadTool $createTool */
        $createTool = $this->makeTool(CreateLeadTool::class);
        $createResponse = json_decode((string) $createTool->handle($this->makeRequest([
            'title' => 'Handoff Tool Test Lead',
            'firstname' => 'Handoff Test',
        ])), true);
        $leadId = $createResponse['lead_id'];

        /** @var HandOffLeadTool $tool */
        $tool = $this->makeTool(HandOffLeadTool::class);
        $response = $tool->handle($this->makeRequest([
            'lead_id' => $leadId,
            'handoff_type' => 'human',
            'conversation_summary' => 'Customer requested a person.',
        ]));

        $result = json_decode((string) $response, true);

        $this->assertTrue($result['success']);
        $this->assertSame($leadId, $result['lead_id']);
        $this->assertSame('human', $result['handoff_type']);

        $lead = Lead::findOrFail($leadId);
        $this->assertEquals(1, $lead->get(ConfigurationEnum::AGENT_HAND_OFF->value));
        $this->assertSame('human', $lead->get(ConfigurationEnum::AGENT_HAND_OFF_TYPE->value));
    }

    public function testHandOffLeadToolRejectsUnsupportedTypeWithoutChangingLead(): void
    {
        /** @var CreateLeadTool $createTool */
        $createTool = $this->makeTool(CreateLeadTool::class);
        $createResponse = json_decode((string) $createTool->handle($this->makeRequest([
            'title' => 'Invalid Handoff Type Lead',
            'firstname' => 'Invalid Handoff',
        ])), true);
        $leadId = $createResponse['lead_id'];

        /** @var HandOffLeadTool $tool */
        $tool = $this->makeTool(HandOffLeadTool::class);
        $response = $tool->handle($this->makeRequest([
            'lead_id' => $leadId,
            'handoff_type' => 'not_configured_by_backend',
        ]));

        $result = json_decode((string) $response, true);

        $this->assertFalse($result['success']);
        $this->assertSame('Unsupported handoff type.', $result['error']);

        $lead = Lead::findOrFail($leadId);
        $this->assertNull($lead->get(ConfigurationEnum::AGENT_HAND_OFF->value));
    }

    public function testGetOrganizationCustomFieldsToolReturnsAllFields(): void
    {
        $org = $this->seedOrganization('GetFields Test Org ' . uniqid());

        /** @var SetOrganizationCustomFieldsTool $setTool */
        $setTool = $this->makeTool(SetOrganizationCustomFieldsTool::class);
        $setTool->handle($this->makeRequest([
            'organization_id' => $org->getId(),
            'fields' => [
                'composite_distress_score' => 72.5,
                'composite_color' => 'orange',
                'composite_last_recomputed_at' => '2026-07-10',
            ],
        ]));

        /** @var GetOrganizationCustomFieldsTool $getTool */
        $getTool = $this->makeTool(GetOrganizationCustomFieldsTool::class);
        $result = json_decode((string) $getTool->handle($this->makeRequest([
            'organization_id' => $org->getId(),
        ])), true);

        $this->assertSame($org->getId(), $result['organization_id']);
        $this->assertArrayHasKey('fields', $result);
        $this->assertEquals(72.5, $result['fields']['composite_distress_score']);
        $this->assertSame('orange', $result['fields']['composite_color']);
        $this->assertSame('2026-07-10', $result['fields']['composite_last_recomputed_at']);
    }

    public function testGetOrganizationCustomFieldsToolReturnsSubsetWhenKeysProvided(): void
    {
        $org = $this->seedOrganization('GetFields Subset Org ' . uniqid());

        /** @var SetOrganizationCustomFieldsTool $setTool */
        $setTool = $this->makeTool(SetOrganizationCustomFieldsTool::class);
        $setTool->handle($this->makeRequest([
            'organization_id' => $org->getId(),
            'fields' => [
                'composite_distress_score' => 55.0,
                'composite_last_recomputed_at' => '2026-07-10',
                'company_profile' => ['companyName' => 'Acme'],
            ],
        ]));

        /** @var GetOrganizationCustomFieldsTool $getTool */
        $getTool = $this->makeTool(GetOrganizationCustomFieldsTool::class);
        $result = json_decode((string) $getTool->handle($this->makeRequest([
            'organization_id' => $org->getId(),
            'keys' => ['composite_last_recomputed_at', 'composite_distress_score'],
        ])), true);

        $this->assertArrayHasKey('composite_last_recomputed_at', $result['fields']);
        $this->assertArrayHasKey('composite_distress_score', $result['fields']);
        $this->assertArrayNotHasKey('company_profile', $result['fields']);
    }

    public function testGetOrganizationCustomFieldsToolReturnsErrorForUnknownOrg(): void
    {
        /** @var GetOrganizationCustomFieldsTool $tool */
        $tool = $this->makeTool(GetOrganizationCustomFieldsTool::class);

        $response = (string) $tool->handle($this->makeRequest([
            'organization_id' => 999999999,
        ]));

        $this->assertStringContainsString('not found', $response);
    }
}
