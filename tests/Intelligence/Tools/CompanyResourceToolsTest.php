<?php

declare(strict_types=1);

namespace Tests\Intelligence\Tools;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Models\LeadType;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentType;
use Kanvas\Intelligence\Agents\Neuron\CompanyConfigurationAgent;
use Kanvas\Intelligence\Agents\Neuron\SystemUserAgent;
use Kanvas\Intelligence\Agents\Neuron\Tools\Company\CompanyResourceTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Company\CopyCompanyReceiverTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Company\ManageCompanyEmailTemplatesTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Company\ManageCompanyLeadTypesTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Company\ManageCompanyPipelineStagesTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Company\ManageCompanyPipelinesTool;
use Kanvas\Templates\Models\Templates;
use Kanvas\Users\Models\UserCompanyApps;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Kanvas\Workflow\Rules\Models\Action;
use Mockery;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Tests\TestCase;

class CompanyResourceToolsTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['mysql', 'ecosystem', 'crm', 'intelligence', 'workflow'];

    private function tenant(): Companies
    {
        $company = Companies::factory()->create(['users_id' => auth()->id()]);
        UserCompanyApps::query()->firstOrCreate([
            'companies_id' => $company->getId(), 'apps_id' => app(Apps::class)->getId(),
        ], ['is_deleted' => 0, 'created_at' => now()]);

        return $company;
    }

    private function tool(
        CompanyResourceTool $tool,
        Companies $company,
        bool $admin = true,
        bool $globalType = true,
    ): CompanyResourceTool {
        $caller = Mockery::mock(auth()->user());
        $caller->shouldReceive('isAdmin')->andReturn($admin);
        $agent = new Agent(['apps_id' => app(Apps::class)->getId(), 'companies_id' => 0, 'user_id' => auth()->id() + 100000]);
        $agent->setRelation('type', new AgentType(['handler' => $globalType ? CompanyConfigurationAgent::class : SystemUserAgent::class]));

        return $tool->withContext(
            app(Apps::class),
            $company,
            auth()->user(),
            $agent,
        )->forRequestingUser($caller);
    }

    private function record(array $result): array
    {
        $this->assertTrue($result['success'], json_encode($result));

        return $result['record'];
    }

    public function testLeadTypesCopyEditAndListKeepTenantsIsolated(): void
    {
        $source = $this->tenant();
        $dest = $this->tenant();
        $scope = Bouncer::scope()->get();
        $tool = $this->tool(new ManageCompanyLeadTypesTool(), $source);
        $original = $this->record($tool($source->uuid, 'create', data_json: '{"name":"Web","description":"Website","config":{"channel":"email"}}'));
        $copy = $this->record($tool($dest->uuid, 'copy', $original['id'], source_company_uuid: $source->uuid));
        $this->assertNotSame($original['id'], $copy['id']);
        $this->assertSame($original['config'], $copy['config']);
        $updated = $this->record($tool($dest->uuid, 'update', $copy['id'], '{"name":"Destination","is_active":0}'));
        $this->assertSame('Website', $updated['description']);
        $this->assertSame('Web', $this->record($tool($source->uuid, 'get', $original['id']))['name']);
        $this->assertFalse($tool($dest->uuid, 'update', $original['id'], '{"name":"Bad"}')['success']);
        $this->assertFalse($tool($dest->uuid, 'create', data_json: '{"name":"Bad","companies_id":1}')['success']);
        $this->assertCount(1, $tool($dest->uuid, 'list')['records']);
        $this->assertSame($scope, Bouncer::scope()->get());
    }

    public function testPipelineAndStageCrudAndCopyReturnDestinationIds(): void
    {
        $source = $this->tenant();
        $dest = $this->tenant();
        $pipelines = $this->tool(new ManageCompanyPipelinesTool(), $source);
        $stages = $this->tool(new ManageCompanyPipelineStagesTool(), $source);
        $pipeline = $this->record($pipelines($source->uuid, 'create', data_json: '{"name":"Sales"}'));
        $stage = $this->record($stages($source->uuid, 'create', data_json: json_encode(['name' => 'New', 'pipelines_id' => $pipeline['id'], 'weight' => 0, 'rotting_days' => 3, 'config' => ['color' => 'blue']])));
        $result = $pipelines($dest->uuid, 'copy', $pipeline['id'], source_company_uuid: $source->uuid);
        $copy = $this->record($result);
        $this->assertSame($copy['stages'][0]['id'], $result['stage_id_map'][$stage['id']]);
        $this->assertNotSame($stage['id'], $copy['stages'][0]['id']);
        $this->assertSame(['color' => 'blue'], $copy['stages'][0]['config']);
        $updated = $this->record($pipelines($dest->uuid, 'update', $copy['id'], '{"name":"Copied Sales"}'));
        $this->assertCount(1, $updated['stages']);
        $this->assertFalse($stages($dest->uuid, 'create', data_json: json_encode(['name' => 'Foreign', 'pipelines_id' => $pipeline['id']]))['success']);
        $this->assertFalse($stages($dest->uuid, 'update', $stage['id'], '{"name":"Foreign"}')['success']);
        $this->record($stages($dest->uuid, 'update', $copy['stages'][0]['id'], '{"weight":8,"config":null}'));
        $this->assertTrue($stages($dest->uuid, 'delete', $copy['stages'][0]['id'])['success']);
        $this->assertTrue($pipelines($dest->uuid, 'delete', $copy['id'])['success']);
        $this->assertCount(1, $this->record($pipelines($source->uuid, 'get', $pipeline['id']))['stages']);
    }

    public function testTemplatesCopyParentsWithoutRenderingAndCanBeRetried(): void
    {
        $source = $this->tenant();
        $dest = $this->tenant();
        $tool = $this->tool(new ManageCompanyEmailTemplatesTool(), $source);
        $parent = $this->record($tool($source->uuid, 'create', data_json: json_encode(['name' => 'layout', 'template' => '<main>{{ $content }}</main>'])));
        $child = $this->record($tool($source->uuid, 'create', data_json: json_encode(['name' => 'welcome', 'template' => 'Hello {{ $user->name }}', 'subject' => 'Welcome', 'parent_template_id' => $parent['id']])));
        $result = $tool($dest->uuid, 'copy', $child['id'], source_company_uuid: $source->uuid);
        $copy = $this->record($result);
        $this->assertSame($child['template'], $copy['template']);
        $this->assertSame($child['subject'], $copy['subject']);
        $this->assertSame($result['template_id_map'][$parent['id']], $copy['parent_template_id']);
        $this->assertNotSame($parent['id'], $copy['parent_template_id']);
        $this->assertSame($copy['id'], $this->record($tool($dest->uuid, 'copy', $child['id'], source_company_uuid: $source->uuid))['id']);
        $this->assertFalse($tool($dest->uuid, 'update', $copy['id'], json_encode(['parent_template_id' => $parent['id']]))['success']);
        $this->assertFalse($tool($dest->uuid, 'update', $copy['parent_template_id'], json_encode(['parent_template_id' => $copy['id']]))['success']);
        $this->assertCount(2, array_filter($tool($dest->uuid, 'list')['records'], fn ($row) => (int) $row['companies_id'] === (int) $dest->getId()));
    }

    public function testCopyConflictRollsBackNewParent(): void
    {
        $source = $this->tenant();
        $dest = $this->tenant();
        $tool = $this->tool(new ManageCompanyEmailTemplatesTool(), $source);
        $parent = $this->record($tool($source->uuid, 'create', data_json: '{"name":"parent-rollback","template":"Parent"}'));
        $child = $this->record($tool($source->uuid, 'create', data_json: json_encode(['name' => 'conflict', 'template' => 'Source', 'parent_template_id' => $parent['id']])));
        $this->record($tool($dest->uuid, 'create', data_json: '{"name":"conflict","template":"Destination"}'));
        $this->assertFalse($tool($dest->uuid, 'copy', $child['id'], source_company_uuid: $source->uuid)['success']);
        $records = array_values(array_filter($tool($dest->uuid, 'list')['records'], fn ($row) => (int) $row['companies_id'] === (int) $dest->getId()));
        $this->assertCount(1, $records);
        $this->assertSame('Destination', $records[0]['template']);
    }

    public function testEveryToolRejectsUnauthorizedHumanWrongAgentAndForeignSource(): void
    {
        $company = $this->tenant();
        foreach ([CopyCompanyReceiverTool::class, ManageCompanyEmailTemplatesTool::class, ManageCompanyPipelinesTool::class, ManageCompanyPipelineStagesTool::class, ManageCompanyLeadTypesTool::class] as $class) {
            $this->assertFalse($this->tool(new $class(), $company, admin: false)($company->uuid, 'list')['success']);
            $this->assertFalse($this->tool(new $class(), $company, globalType: false)($company->uuid, 'list')['success']);
            $this->assertFalse($this->tool(new $class(), $company)($company->uuid, 'copy', 1, source_company_uuid: fake()->uuid())['success']);
            $this->assertFalse(new $class()($company->uuid, 'list')['success']);
        }
    }

    public function testListsEveryPageAndSharedTemplatesAreReadOnly(): void
    {
        $company = $this->tenant();
        for ($i = 0; $i < 51; $i++) {
            LeadType::create(['apps_id' => app(Apps::class)->getId(), 'companies_id' => $company->getId(), 'name' => 'Page ' . $i, 'description' => 'Pagination']);
        }
        $tool = $this->tool(new ManageCompanyLeadTypesTool(), $company);
        $first = $tool($company->uuid, 'list');
        $second = $tool($company->uuid, 'list', page: 2);
        $this->assertCount(50, $first['records']);
        $this->assertTrue($first['has_more']);
        $this->assertCount(1, $second['records']);
        $this->assertFalse($second['has_more']);
        $shared = Templates::create(['apps_id' => app(Apps::class)->getId(), 'companies_id' => 0, 'users_id' => auth()->id(), 'name' => 'Shared ' . fake()->uuid(), 'template' => 'Original']);
        $templates = $this->tool(new ManageCompanyEmailTemplatesTool(), $company);
        $this->record($templates($company->uuid, 'get', $shared->getId()));
        $this->assertFalse($templates($company->uuid, 'update', $shared->getId(), '{"template":"Forbidden"}')['success']);
        $this->assertSame('Original', $shared->fresh()->template);
    }

    public function testCannotDeletePipelinesOrStagesReferencedByLeads(): void
    {
        $company = $this->tenant();
        $pipelines = $this->tool(new ManageCompanyPipelinesTool(), $company);
        $stages = $this->tool(new ManageCompanyPipelineStagesTool(), $company);
        $pipeline = $this->record($pipelines($company->uuid, 'create', data_json: '{"name":"Protected"}'));
        $stage = $this->record($stages($company->uuid, 'create', data_json: json_encode(['name' => 'Used', 'pipelines_id' => $pipeline['id']])));
        Lead::withoutEvents(fn () => Lead::create([
            'apps_id' => app(Apps::class)->getId(), 'companies_id' => $company->getId(),
            'users_id' => auth()->id(), 'title' => 'Protected fixture', 'uuid' => fake()->uuid(),
            'companies_branches_id' => $company->branches()->firstOrFail()->getId(),
            'leads_receivers_id' => 0, 'leads_owner_id' => auth()->id(),
            'pipeline_id' => $pipeline['id'], 'pipeline_stage_id' => $stage['id'],
        ]));
        $this->assertFalse($stages($company->uuid, 'delete', $stage['id'])['success']);
        $this->assertFalse($pipelines($company->uuid, 'delete', $pipeline['id'])['success']);
    }

    public function testReceiverCopyUsesNewEndpointAndExplicitDestinationConfiguration(): void
    {
        $source = $this->tenant();
        $dest = $this->tenant();
        $action = Action::where('kind', 'receiver')->where('model_name', 'like', 'Kanvas%')->notDeleted()->firstOrFail();
        $original = ReceiverWebhook::create([
            'apps_id' => app(Apps::class)->getId(), 'companies_id' => $source->getId(),
            'users_id' => auth()->id(), 'action_id' => $action->getId(),
            'name' => 'Source receiver', 'description' => 'Copy fixture',
            'configuration' => ['region' => 'source'], 'is_active' => true,
        ]);
        $original->run_async = false;
        $original->saveOrFail();
        $tool = $this->tool(new CopyCompanyReceiverTool(), $source);
        $this->assertFalse($tool($dest->uuid, 'copy', $original->getId(), source_company_uuid: $source->uuid)['success']);
        $result = $tool(
            company_uuid: $dest->uuid,
            operation: 'copy',
            id: $original->getId(),
            data_json: '{"configuration":{"region":"destination"}}',
            source_company_uuid: $source->uuid,
        );
        $copy = $this->record($result);
        $this->assertNotSame($original->uuid, $copy['uuid']);
        $this->assertSame($original->action_id, $copy['action_id']);
        $this->assertFalse($copy['is_active']);
        $this->assertFalse($copy['run_async']);
        $this->assertSame(['region' => 'destination'], $copy['configuration']);
        $this->assertSame($copy['id'], $result['receiver_id_map'][$original->getId()]);
        $this->assertSame($dest->getId(), ReceiverWebhook::findOrFail($copy['id'])->companies_id);
        $this->assertSame(['region' => 'source'], $original->fresh()->configuration);
        $this->assertTrue($original->fresh()->is_active);
        $this->assertFalse($tool($source->uuid, 'get', $copy['id'])['success']);
        $this->assertFalse($tool($dest->uuid, 'copy', $original->getId(), '{"configuration":{}}', $source->uuid)['success']);
        $this->assertCount(1, $tool($dest->uuid, 'list')['records']);
    }

    public function testReceiverCredentialsAreRedactedAndNeverCopied(): void
    {
        $source = $this->tenant();
        $dest = $this->tenant();
        $action = Action::where('kind', 'receiver')->where('model_name', 'like', 'Kanvas%')->notDeleted()->firstOrFail();
        $original = ReceiverWebhook::create([
            'apps_id' => app(Apps::class)->getId(), 'companies_id' => $source->getId(),
            'users_id' => auth()->id(), 'action_id' => $action->getId(),
            'name' => 'Credential fixture', 'configuration' => ['nested' => ['api_key' => 'test-fixture-only']],
        ]);
        $tool = $this->tool(new CopyCompanyReceiverTool(), $source);
        $read = $this->record($tool($source->uuid, 'get', $original->getId()));
        $this->assertSame('[REDACTED]', $read['configuration']['nested']['api_key']);
        $this->assertStringNotContainsString('test-fixture-only', json_encode($tool($source->uuid, 'list')));
        $this->assertFalse($tool($dest->uuid, 'copy', $original->getId(), '{"configuration":{"api_key":"test-fixture-only"}}', $source->uuid)['success']);
        $this->assertFalse($tool($dest->uuid, 'copy', $original->getId(), '{"configuration":{"x":"[REDACTED]"}}', $source->uuid)['success']);
        $copy = $this->record($tool($dest->uuid, 'copy', $original->getId(), '{"configuration":{}}', $source->uuid));
        $this->assertSame([], $copy['configuration']);
        $this->assertFalse($tool($dest->uuid, 'delete', $copy['id'])['success']);
    }
}
