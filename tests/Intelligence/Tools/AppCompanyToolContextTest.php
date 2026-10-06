<?php

declare(strict_types=1);

namespace Tests\Intelligence\Tools;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\AccessControlList\Enums\RolesEnums;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentType;
use Kanvas\Intelligence\Agents\Neuron\CompanyConfigurationAgent;
use Kanvas\Intelligence\Agents\Neuron\Tools\Company\ManageAppCompanySettingTool;
use Kanvas\Intelligence\Agents\Services\AppCompanyToolExecutor;
use Kanvas\Users\Models\UserCompanyApps;
use Kanvas\Users\Models\Users;
use Mockery;
use Kanvas\Intelligence\Agents\Neuron\Tools\Company\ManageCompanySettingTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\NervousSystem\GetAgentConfigurationTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\NervousSystem\HireAgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\NervousSystem\ListAgentsTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\NervousSystem\GrantAgentToolsTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\NervousSystem\UpdateAgentInstructionsTool;

use Silber\Bouncer\BouncerFacade as Bouncer;
use Kanvas\Intelligence\Agents\Neuron\Tools\Workflow\ListWorkflowOptionsTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Workflow\ListCompanyWorkflowsTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Workflow\CreateCompanyWorkflowTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Workflow\UpdateCompanyWorkflowTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Workflow\CreateCompanyReceiverTool;
use Tests\TestCase;

class AppCompanyToolContextTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['mysql', 'ecosystem', 'intelligence', 'workflow'];

    private function tenant(bool $associated = true): Companies
    {
        $company = Companies::factory()->create(['users_id' => auth()->id()]);
        // Factories may attach automatically; set this fixture's association explicitly.
        UserCompanyApps::query()->where('companies_id', $company->getId())->delete();
        if ($associated) {
            UserCompanyApps::query()->insert([
                'companies_id' => $company->getId(), 'apps_id' => app(Apps::class)->getId(),
                'is_deleted' => 0, 'created_at' => now(),
            ]);
        }

        return $company;
    }

    private function globalAgent(): Agent
    {
        return new Agent([
            'apps_id' => app(Apps::class)->getId(), 'companies_id' => 0,
            'user_id' => auth()->id() + 100000, 'is_deleted' => false,
        ])->setRelation('type', new AgentType(['handler' => CompanyConfigurationAgent::class]));
    }

    private function caller(bool $admin = true): Users
    {
        $caller = Mockery::mock(auth()->user());
        $caller->shouldReceive('isAdmin')->andReturnUsing(function () use ($admin): bool {
            $this->assertSame(RolesEnums::getScope(app(Apps::class), global: true), Bouncer::scope()->get());

            return $admin;
        });

        return $caller;
    }

    private function tool(Companies $chatCompany, ?Users $caller, ?Agent $agent = null): ManageAppCompanySettingTool
    {
        return new ManageAppCompanySettingTool()
            ->withContext(app(Apps::class), $chatCompany, auth()->user(), $agent ?? $this->globalAgent())
            ->forRequestingUser($caller);
    }

    public function testExistingSettingToolAcceptsAnExplicitCompanyAndKeepsItsOriginalContext(): void
    {
        $source = $this->tenant();
        $destination = $this->tenant();
        $tool = new ManageCompanySettingTool()
            ->withContext(app(Apps::class), $source, auth()->user(), $this->globalAgent())
            ->forRequestingUser($this->caller());
        $key = 'direct_context_' . fake()->uuid();
        $oldScope = Bouncer::scope()->get();
        try {
            $source->set($key, 'source', false);
            $result = $tool('set', $key, '"destination"', $destination->uuid);
            $this->assertTrue($result['success']);
            // The omitted parameter still reads the original company, not the last destination.
            Bouncer::scope()->to(RolesEnums::getScope(app(Apps::class), global: true));
            $this->assertSame('source', $tool('get', $key)['value']);
            $this->assertSame('destination', $tool('get', $key, company_uuid: $destination->uuid)['value']);
        } finally {
            Bouncer::scope()->to($oldScope);
            $source->del($key);
            $destination->del($key);
        }
    }

    public function testAllAdaptedAgentToolsRefuseCompanyParameterForAnotherType(): void
    {
        $company = $this->tenant();
        $agent = $this->globalAgent();
        $agent->setRelation('type', new AgentType(['handler' => \Kanvas\Intelligence\Agents\Neuron\SystemUserAgent::class]));
        $calls = [
            [new ListAgentsTool($agent), []],
            [new ListWorkflowOptionsTool(), []],
            [new ListCompanyWorkflowsTool(), []],
            [new CreateCompanyWorkflowTool(), ['name' => 'Denied', 'entity' => 'Lead', 'trigger' => 'created', 'actions' => 'Denied']],
            [new UpdateCompanyWorkflowTool(), ['workflow_id' => 1]],
            [new CreateCompanyReceiverTool(), ['receiver' => 'Denied', 'name' => 'Denied']],
            [new HireAgentTool($agent), ['name' => 'Denied', 'role' => 'Worker', 'instructions' => 'No work']],
            [new GetAgentConfigurationTool(), ['agent_id' => 1]],
            [new UpdateAgentInstructionsTool($agent), ['agent_id' => 1, 'reason' => 'Denied']],
            [new GrantAgentToolsTool($agent), ['agent_id' => 1, 'tools' => 'Read Channel Window']],
        ];
        foreach ($calls as [$tool, $arguments]) {
            $tool->withContext(app(Apps::class), $company, auth()->user(), $agent)->forRequestingUser($this->caller());
            $result = $tool(...[...$arguments, 'company_uuid' => $company->uuid]);
            $this->assertFalse($result['success']);
            $this->assertStringContainsString('Only the Company Configuration Administrator', $result['error']);
        }
    }


    public function testWorkflowToolsUseExplicitCompanyWithoutChangingChatContext(): void
    {
        $source = $this->tenant();
        $destination = $this->tenant();
        $agent = $this->globalAgent();
        $context = fn ($tool) => $tool->withContext(app(Apps::class), $source, auth()->user(), $agent)
            ->forRequestingUser($this->caller());
        $module = \Kanvas\SystemModules\Repositories\SystemModulesRepository::getByModelName(\Kanvas\Guild\Leads\Models\Lead::class, app(Apps::class));
        $trigger = \Kanvas\Workflow\Rules\Models\RuleType::create(['name' => 'context-test-' . fake()->uuid()]);
        $action = \Kanvas\Workflow\Rules\Models\Action::where('kind', 'workflow')->where('model_name', 'like', 'Kanvas%')->where('is_deleted', 0)->get()->first(fn ($action) => $action->requiredParamNames() === []);
        $this->assertNotNull($action);
        $scope = Bouncer::scope()->get();
        $create = $context(new CreateCompanyWorkflowTool());
        $result = $create('Explicit ' . fake()->uuid(), $module->model_name, $trigger->name, $action->name, company_uuid: $destination->uuid);
        $this->assertTrue($result['created'] ?? false, json_encode($result));
        $rule = \Kanvas\Workflow\Rules\Models\Rule::findOrFail($result['workflow_id']);
        $this->assertSame($destination->getId(), (int) $rule->companies_id);
        $list = $context(new ListCompanyWorkflowsTool());
        $this->assertSame([], $list($rule->name)['workflows']);
        $this->assertSame($rule->getId(), $list($rule->name, $destination->uuid)['workflows'][0]['workflow_id']);
        $update = $context(new UpdateCompanyWorkflowTool());
        $this->assertFalse($update($rule->getId(), name: 'Wrong', company_uuid: $source->uuid)['updated']);
        $this->assertTrue($update($rule->getId(), name: 'Updated destination', company_uuid: $destination->uuid)['updated']);
        $this->assertSame('Updated destination', $rule->fresh()->name);
        $this->assertSame('success', $context(new ListWorkflowOptionsTool())('triggers', company_uuid: $destination->uuid)['status']);
        $receiver = \Kanvas\Workflow\Rules\Models\Action::where('kind', 'receiver')->where('model_name', 'like', 'Kanvas%')->where('is_deleted', 0)->firstOrFail();
        $endpoint = $context(new CreateCompanyReceiverTool())($receiver->name, 'Explicit receiver', company_uuid: $destination->uuid);
        $this->assertTrue($endpoint['created'], json_encode($endpoint));
        $this->assertSame($destination->getId(), (int) \Kanvas\Workflow\Models\ReceiverWebhook::findOrFail($endpoint['receiver_id'])->companies_id);
        $this->assertSame($scope, Bouncer::scope()->get());
        $this->assertSame(0, (int) $agent->companies_id);
    }

    public function testReadsAgentFromSourceAndHiresCopyInDestination(): void
    {
        $source = $this->tenant();
        $destination = $this->tenant();
        $global = Agent::factory()->withAppId(app(Apps::class)->getId())->withCompanyId(0)
            ->create(['user_id' => Users::factory()->create()->getId()]);
        $global->setRelation('type', new AgentType(['handler' => CompanyConfigurationAgent::class]));
        $original = Agent::factory()->withAppId(app(Apps::class)->getId())->withCompanyId($source->getId())
            ->create(['name' => 'Source ' . fake()->uuid(), 'instructions' => 'Only answer the supplied question.']);
        $read = new GetAgentConfigurationTool()->withContext(app(Apps::class), $source, auth()->user(), $global)
            ->forRequestingUser($this->caller());
        $snapshot = $read($original->getId(), $source->uuid);
        $this->assertTrue($snapshot['success']);
        $this->assertFalse($read($original->getId(), $destination->uuid)['success']);
        $hire = new HireAgentTool($global)->withContext(app(Apps::class), $source, auth()->user(), $global)
            ->forRequestingUser($this->caller());
        $result = $hire(
            name: 'Copied ' . fake()->uuid(), role: 'Assistant',
            instructions: $snapshot['agent']['instructions'], company_uuid: $destination->uuid,
        );
        $this->assertTrue($result['hired'], json_encode($result));
        $copy = Agent::findOrFail($result['agent_id']);
        $this->assertSame($destination->getId(), (int) $copy->companies_id);
        $this->assertSame($original->instructions, $copy->instructions);
        $this->assertSame($source->getId(), (int) $original->fresh()->companies_id);
        $edit = new UpdateAgentInstructionsTool($global)
            ->withContext(app(Apps::class), $source, auth()->user(), $global)->forRequestingUser($this->caller());
        $edited = $edit($copy->getId(), 'Adapt the destination copy.', instructions: 'Destination instructions.', company_uuid: $destination->uuid);
        $this->assertSame('success', $edited['status'], json_encode($edited));
        $this->assertSame('Destination instructions.', $copy->fresh()->instructions);
        $this->assertSame('Only answer the supplied question.', $original->fresh()->instructions);
        $this->assertSame('error', $edit($original->getId(), 'Wrong company', instructions: 'Do not save.', company_uuid: $destination->uuid)['status']);
        $grant = new GrantAgentToolsTool($global)
            ->withContext(app(Apps::class), $source, auth()->user(), $global)->forRequestingUser($this->caller());
        $granted = $grant($copy->getId(), 'Read Channel Window', company_uuid: $destination->uuid);
        $this->assertSame('success', $granted['status'], json_encode($granted));
        $sub = $hire(
            name: 'Child ' . fake()->uuid(), role: 'Assistant', instructions: 'Answer your parent.',
            is_sub_agent: true, parent_agent_id: $copy->getId(), company_uuid: $destination->uuid,
        );
        $this->assertTrue($sub['hired'], json_encode($sub));
        $this->assertSame($copy->getId(), (int) $sub['parent_id']);
        $wrongParent = $hire(
            name: 'Refused ' . fake()->uuid(), role: 'Assistant', instructions: 'Answer your parent.',
            is_sub_agent: true, parent_agent_id: $original->getId(), company_uuid: $destination->uuid,
        );
        $this->assertFalse($wrongParent['hired']);
    }

    public function testSourceReadAndDestinationWriteDoNotSwitchTheUserOrAgent(): void
    {
        $source = $this->tenant();
        $destination = $this->tenant();
        $user = auth()->user();
        $defaultCompany = $user->default_company;
        $currentCompany = $user->getCurrentCompany()->getId();
        $agent = $this->globalAgent();
        $tool = $this->tool($source, $this->caller(), $agent);
        $key = 'context_test_' . fake()->uuid();
        $scope = Bouncer::scope()->get();
        try {
            $source->set($key, ['label' => 'source'], false);
            $read = $tool($source->uuid, 'get', $key);
            $this->assertTrue($read['success']);
            $this->assertSame(['label' => 'source'], $read['value']);
            $write = $tool($destination->uuid, 'set', $key, '{"label":"destination"}');
            $this->assertTrue($write['success'], json_encode($write));
            $this->assertSame($destination->uuid, $write['company_uuid']);
            $this->assertSame(['label' => 'source'], $tool($source->uuid, 'get', $key)['value']);
            $this->assertSame(['label' => 'destination'], $tool($destination->uuid, 'get', $key)['value']);
            $this->assertSame($scope, Bouncer::scope()->get());
            $this->assertSame(0, (int) $agent->companies_id);
            $this->assertSame($defaultCompany, $user->fresh()->default_company);
            $this->assertSame($currentCompany, $user->getCurrentCompany()->getId());
        } finally {
            $source->del($key);
            $destination->del($key);
        }
    }

    public function testRejectsCompanyOutsideAppAndDeletedAppAssociation(): void
    {
        $chat = $this->tenant();
        $foreign = $this->tenant(false);
        $tool = $this->tool($chat, $this->caller());
        $this->assertFalse($tool($foreign->uuid, 'set', 'ai', 'true')['success']);
        UserCompanyApps::query()->where('companies_id', $chat->getId())->update(['is_deleted' => 1]);
        $this->assertFalse($tool($chat->uuid, 'get', 'ai')['success']);
        $this->assertFalse($tool('not-a-uuid', 'get', 'ai')['success']);
    }

    public function testRejectsCompanyBoundAgentAndMissingOrUnauthorizedHuman(): void
    {
        $company = $this->tenant();
        $agent = $this->globalAgent();
        $agent->companies_id = $company->getId();
        $this->assertFalse($this->tool($company, $this->caller(), $agent)($company->uuid, 'catalog')['success']);
        $this->assertFalse($this->tool($company, null)($company->uuid, 'catalog')['success']);
        $this->assertFalse($this->tool($company, $this->caller(false))($company->uuid, 'catalog')['success']);
        $agent->companies_id = 0;
        $agent->apps_id = app(Apps::class)->getId() + 100000;
        $this->assertFalse($this->tool($company, $this->caller(), $agent)($company->uuid, 'catalog')['success']);
    }

    public function testAnotherGlobalAgentTypeCannotSelectACompany(): void
    {
        $company = $this->tenant();
        $agent = $this->globalAgent();
        $agent->setRelation('type', new AgentType(['handler' => \Kanvas\Intelligence\Agents\Neuron\SystemUserAgent::class]));
        $result = $this->tool($company, $this->caller(), $agent)($company->uuid, 'catalog');
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Only the Company Configuration Administrator', $result['error']);
    }

    public function testNeverUsesTheAgentsIdentityAsTheHuman(): void
    {
        $company = $this->tenant();
        $agent = $this->globalAgent();
        $agent->user_id = auth()->id();
        $this->assertFalse($this->tool($company, $this->caller(), $agent)($company->uuid, 'catalog')['success']);
    }

    public function testRestoresAclScopeWhenTheOperationThrows(): void
    {
        $company = $this->tenant();
        $previous = Bouncer::scope()->get();
        try {
            new AppCompanyToolExecutor()->execute(
                app(Apps::class), $this->globalAgent(), $this->caller(), $company->uuid,
                function (): array { throw new AuthorizationException('Simulated failure'); },
            );
            $this->fail('Expected operation to throw.');
        } catch (AuthorizationException $e) {
            $this->assertSame('Simulated failure', $e->getMessage());
            $this->assertSame($previous, Bouncer::scope()->get());
        }
    }
}
