<?php

declare(strict_types=1);

namespace Tests\Intelligence\NervousSystem;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Actions\CreateAgentAction;
use Kanvas\Intelligence\Agents\DataTransferObject\Agent as AgentData;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentType;
use Kanvas\NervousSystem\Capability\Actions\AttachToolToAgentTypeAction;
use Kanvas\NervousSystem\Capability\Actions\CreateToolAction;
use Kanvas\NervousSystem\Capability\Actions\DetachToolFromAgentTypeAction;
use Kanvas\NervousSystem\Capability\DataTransferObject\Tool as ToolData;
use Kanvas\NervousSystem\Capability\Enums\ToolTypeEnum;
use Kanvas\NervousSystem\Capability\Models\Tool;
use Kanvas\NervousSystem\Capability\Services\CapabilityProvider;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

class AgentToolSelectionTest extends TestCase
{
    public function testAttachToolToAgentTypePersistsPivotRow(): void
    {
        $tool = $this->makeTool(['laravel']);
        $type = $this->makeAgentType('laravel');

        new AttachToolToAgentTypeAction($tool, $type)->execute();

        $this->assertDatabaseHas(
            'nervous_system_tool_agent_types',
            ['tool_id' => $tool->id, 'agent_type_id' => $type->id],
            'intelligence',
        );
    }

    public function testAttachToolToAgentTypeIdempotent(): void
    {
        $tool = $this->makeTool(['laravel']);
        $type = $this->makeAgentType('laravel');

        new AttachToolToAgentTypeAction($tool, $type)->execute();
        new AttachToolToAgentTypeAction($tool, $type)->execute();

        $count = DB::connection('intelligence')
            ->table('nervous_system_tool_agent_types')
            ->where('tool_id', $tool->id)
            ->where('agent_type_id', $type->id)
            ->count();

        $this->assertSame(1, $count);
    }

    public function testDetachToolFromAgentTypeRemovesPivotRow(): void
    {
        $tool = $this->makeTool(['laravel']);
        $type = $this->makeAgentType('laravel');

        new AttachToolToAgentTypeAction($tool, $type)->execute();
        new DetachToolFromAgentTypeAction($tool, $type)->execute();

        $this->assertDatabaseMissing(
            'nervous_system_tool_agent_types',
            ['tool_id' => $tool->id, 'agent_type_id' => $type->id],
            'intelligence',
        );
    }

    public function testToolCanBelongToMultipleAgentTypes(): void
    {
        $tool = $this->makeTool(['laravel', 'neuron']);
        $typeA = $this->makeAgentType('laravel');
        $typeB = $this->makeAgentType('neuron');

        new AttachToolToAgentTypeAction($tool, $typeA)->execute();
        new AttachToolToAgentTypeAction($tool, $typeB)->execute();

        $tool->refresh();
        $typeIds = $tool->agentTypes()->pluck('agent_type_id')->all();

        $this->assertContains($typeA->id, $typeIds);
        $this->assertContains($typeB->id, $typeIds);
    }

    public function testAgentTypeToolsRelationReturnsAttachedTools(): void
    {
        $type = $this->makeAgentType('laravel');
        $toolA = $this->makeTool(['laravel']);
        $toolB = $this->makeTool(['laravel']);

        new AttachToolToAgentTypeAction($toolA, $type)->execute();
        new AttachToolToAgentTypeAction($toolB, $type)->execute();

        $type->refresh();
        $toolIds = $type->tools()->pluck('id')->all();

        $this->assertContains($toolA->id, $toolIds);
        $this->assertContains($toolB->id, $toolIds);
    }

    public function testCapabilityProviderReturnsSelectedToolsFirst(): void
    {
        $type = $this->makeAgentType('laravel');
        $typeTool = $this->makeTool(['laravel']);
        $selectedTool = $this->makeTool(['laravel']);

        new AttachToolToAgentTypeAction($typeTool, $type)->execute();

        $agent = $this->makeAgent($type);
        $agent->selectedTools()->sync([$selectedTool->getId()]);

        $tools = new CapabilityProvider()->getActiveTools($agent);
        $toolIds = $tools->pluck('id')->all();

        $this->assertContains($selectedTool->id, $toolIds);
        $this->assertNotContains($typeTool->id, $toolIds, 'Type tool should be overridden by selection');
    }

    public function testCapabilityProviderReturnsEmptyWhenNoToolsSelected(): void
    {
        $type = $this->makeAgentType('laravel');
        $typeTool = $this->makeTool(['laravel']);
        new AttachToolToAgentTypeAction($typeTool, $type)->execute();

        $agent = $this->makeAgent($type);

        $tools = new CapabilityProvider()->getActiveTools($agent);

        $this->assertCount(0, $tools, 'Type tools are catalog only — not returned without explicit selection');
    }

    public function testCapabilityProviderFiltersByFrameworkOnSelectedTools(): void
    {
        $type = $this->makeAgentType('laravel');
        $laravelTool = $this->makeTool(['laravel']);
        $neuronTool = $this->makeTool(['neuron']);

        $agent = $this->makeAgent($type);
        $agent->selectedTools()->sync([$laravelTool->getId(), $neuronTool->getId()]);

        $tools = new CapabilityProvider()->getActiveTools($agent, 'laravel');
        $toolIds = $tools->pluck('id')->all();

        $this->assertContains($laravelTool->id, $toolIds);
        $this->assertNotContains($neuronTool->id, $toolIds);
    }

    public function testAgentSelectedToolsRelationSyncs(): void
    {
        $type = $this->makeAgentType('laravel');
        $toolA = $this->makeTool(['laravel']);
        $toolB = $this->makeTool(['laravel']);

        $agent = $this->makeAgent($type);
        $agent->selectedTools()->sync([$toolA->getId(), $toolB->getId()]);

        $agent->refresh();
        $ids = $agent->selectedTools()->pluck('id')->all();

        $this->assertContains($toolA->id, $ids);
        $this->assertContains($toolB->id, $ids);
    }

    public function testCreateAgentInheritsToolsFromAgentType(): void
    {
        $type = $this->makeAgentType('laravel');
        $toolA = $this->makeTool(['laravel']);
        $toolB = $this->makeTool(['laravel']);

        new AttachToolToAgentTypeAction($toolA, $type)->execute();
        new AttachToolToAgentTypeAction($toolB, $type)->execute();

        $agent = new CreateAgentAction($this->makeAgentData($type))->execute();

        $toolIds = $agent->selectedTools()->pluck('id')->all();

        $this->assertContains($toolA->id, $toolIds);
        $this->assertContains($toolB->id, $toolIds);
    }

    public function testCreateAgentWithToollessTypeSelectsNoTools(): void
    {
        $type = $this->makeAgentType('laravel');

        $agent = new CreateAgentAction($this->makeAgentData($type))->execute();

        $this->assertCount(0, $agent->selectedTools()->get());
    }

    public function testCreateAgentWithExplicitToolsSkipsTypeInheritance(): void
    {
        $type = $this->makeAgentType('laravel');
        $typeTool = $this->makeTool(['laravel']);
        new AttachToolToAgentTypeAction($typeTool, $type)->execute();

        $explicitTool = $this->makeTool(['laravel']);

        $agent = new CreateAgentAction(
            $this->makeAgentData($type, [$explicitTool]),
        )->execute();

        $toolIds = $agent->selectedTools()->pluck('id')->all();

        $this->assertContains($explicitTool->id, $toolIds);
        $this->assertNotContains($typeTool->id, $toolIds, 'Explicit tools must win over type inheritance');
    }

    public function testCreateAgentWithEmptyExplicitToolsSelectsNone(): void
    {
        $type = $this->makeAgentType('laravel');
        $typeTool = $this->makeTool(['laravel']);
        new AttachToolToAgentTypeAction($typeTool, $type)->execute();

        $agent = new CreateAgentAction(
            $this->makeAgentData($type, []),
        )->execute();

        $this->assertCount(
            0,
            $agent->selectedTools()->get(),
            'An explicit empty tool list means no tools — not type inheritance',
        );
    }

    public function testCreateAgentDoesNotOverrideExistingSelectionOnReCreate(): void
    {
        $type = $this->makeAgentType('laravel');
        $typeTool = $this->makeTool(['laravel']);
        new AttachToolToAgentTypeAction($typeTool, $type)->execute();

        $dto = $this->makeAgentData($type);
        $agent = new CreateAgentAction($dto)->execute();

        $explicitTool = $this->makeTool(['laravel']);
        $agent->selectedTools()->sync([$explicitTool->getId()]);

        $agentAgain = new CreateAgentAction($dto)->execute();

        $this->assertSame($agent->id, $agentAgain->id);

        $toolIds = $agentAgain->selectedTools()->pluck('id')->all();
        $this->assertContains($explicitTool->id, $toolIds);
        $this->assertNotContains($typeTool->id, $toolIds);
    }

    private function makeAgentData(AgentType $type, ?array $tools = null): AgentData
    {
        $user = $this->user();

        return new AgentData(
            app: $this->app(),
            company: $user->getCurrentCompany(),
            user: $user,
            agentType: $type,
            name: 'Agent-' . uniqid(),
            role: [],
            is_active: true,
            tools: $tools,
        );
    }

    private function app(): Apps
    {
        return app(Apps::class);
    }

    private function user(): Users
    {
        /** @var Users $user */
        $user = auth()->user();

        return $user;
    }

    private function makeTool(array $frameworks): Tool
    {
        return new CreateToolAction(
            new ToolData(
                app: $this->app(),
                name: 'tool-' . uniqid(),
                frameworks: $frameworks,
                toolType: ToolTypeEnum::CUSTOM,
            ),
        )->execute();
    }

    private function makeAgentType(string $provider): AgentType
    {
        $app = $this->app();
        $type = new AgentType();
        $type->uuid = Str::uuid()->toString();
        $type->apps_id = $app->getId();
        $type->name = 'Type-' . uniqid();
        $type->provider = $provider;
        $type->description = 'Test agent type';
        $type->config = [];
        $type->role = 'test-role';
        $type->is_active = true;
        $type->is_published = false;
        $type->is_multi_agent = false;
        $type->multi_agent_list = [];
        $type->saveOrFail();

        return $type;
    }

    private function makeAgent(AgentType $type): Agent
    {
        $app = $this->app();
        $user = $this->user();
        $company = $user->getCurrentCompany();

        $agent = new Agent();
        $agent->uuid = Str::uuid()->toString();
        $agent->apps_id = $app->getId();
        $agent->companies_id = $company->getId();
        $agent->agent_type_id = $type->id;
        $agent->user_id = $user->getId();
        $agent->name = 'Agent-' . uniqid();
        $agent->slug = 'agent-' . uniqid();
        $agent->config = [];
        $agent->role = [];
        $agent->is_active = true;
        $agent->saveOrFail();

        return $agent;
    }
}
