<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Actions\CreateAgentAction;
use Kanvas\Intelligence\Agents\Actions\UpdateAgentAction;
use Kanvas\Intelligence\Agents\DataTransferObject\Agent as AgentData;
use Kanvas\Intelligence\Agents\Laravel\KanvasGenericLaravelAgent;
use Kanvas\Intelligence\Agents\Laravel\SubAgents\DynamicSubAgent;
use Kanvas\Intelligence\Agents\Laravel\Tools\Inventory\InventorySearchTool;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentType;
use Kanvas\NervousSystem\Capability\Actions\CreateToolAction;
use Kanvas\NervousSystem\Capability\DataTransferObject\Tool as ToolData;
use Kanvas\NervousSystem\Capability\Enums\ToolTypeEnum;
use Kanvas\NervousSystem\Capability\Models\Tool;
use Tests\TestCase;

class DynamicSubAgentTest extends TestCase
{
    use DatabaseTransactions;

    private function makeSubAgentRecord(
        ?string $soul = null,
        ?string $instructions = null,
        ?string $outputFormat = null,
    ): Agent {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $type = AgentType::factory()->withAppId($app->id)->create(['provider' => 'laravel']);

        return Agent::factory()
            ->withAppId($app->id)
            ->withCompanyId($company->id)
            ->create([
                'agent_type_id' => $type->id,
                'soul' => $soul,
                'instructions' => $instructions,
                'output_format' => $outputFormat,
                'is_sub_agent' => true,
            ]);
    }

    public function testCreateAgentWithIsSubAgentAutoCreatesTool(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $type = AgentType::factory()
            ->withAppId($app->id)
            ->create(['provider' => 'laravel']);

        $dto = new AgentData(
            app: $app,
            company: $company,
            user: $user,
            agentType: $type,
            name: 'Sub Agent ' . uniqid(),
            role: 'assistant',
            is_active: true,
            isSubAgent: true,
        );

        $agent = new CreateAgentAction($dto)->execute();

        $this->assertTrue($agent->is_sub_agent);

        $toolExists = Tool::query()
            ->where('agents_id', $agent->getId())
            ->exists();

        $this->assertTrue($toolExists);

        $tool = Tool::query()->where('agents_id', $agent->getId())->first();
        $this->assertSame(ToolTypeEnum::SUB_AGENT->value, $tool->tool_type);
    }

    public function testCreateAgentWithoutIsSubAgentDoesNotCreateTool(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $type = AgentType::factory()->withAppId($app->id)->create(['provider' => 'laravel']);

        $dto = new AgentData(
            app: $app,
            company: $company,
            user: $user,
            agentType: $type,
            name: 'Regular Agent ' . uniqid(),
            role: 'assistant',
            is_active: true,
            isSubAgent: false,
        );

        $agent = new CreateAgentAction($dto)->execute();

        $this->assertFalse($agent->is_sub_agent);
        $this->assertFalse(
            Tool::query()->where('agents_id', $agent->getId())->exists()
        );
    }

    public function testDynamicSubAgentUsesAgentInstructions(): void
    {
        $agentRecord = $this->makeSubAgentRecord(
            soul: 'You are a specialist.',
            instructions: 'Step 1: analyse. Step 2: respond.',
            outputFormat: '{"result": "..."}',
        );

        $subAgent = new DynamicSubAgent($agentRecord);

        $instructions = $subAgent->instructions();

        $this->assertStringContainsString('You are a specialist.', $instructions);
        $this->assertStringContainsString('Step 1: analyse.', $instructions);
        $this->assertStringContainsString('{"result": "..."}', $instructions);
    }

    public function testDynamicSubAgentNameAndDescription(): void
    {
        $agentRecord = $this->makeSubAgentRecord(soul: 'Deduplication expert.');

        $subAgent = new DynamicSubAgent($agentRecord);

        $this->assertSame(
            str_replace('-', '_', \Illuminate\Support\Str::snake($agentRecord->name)),
            str_replace('-', '_', $subAgent->name())
        );
        $this->assertSame('Deduplication expert.', $subAgent->description());
    }

    public function testDynamicSubAgentLoadsItsOwnTools(): void
    {
        $app = app(Apps::class);

        $agentRecord = $this->makeSubAgentRecord();

        $tool = new CreateToolAction(
            new ToolData(
                app: $app,
                name: 'inventory-search-' . uniqid(),
                frameworks: ['laravel'],
                toolType: ToolTypeEnum::CUSTOM,
                handler: InventorySearchTool::class,
            ),
        )->execute();

        $agentRecord->selectedTools()->sync([$tool->getId()]);

        $subAgent = new DynamicSubAgent($agentRecord);

        $agentTools = iterator_to_array((function ($tools) {
            yield from $tools;
        })($subAgent->agentTools()));

        $this->assertCount(1, $agentTools);
        $this->assertInstanceOf(InventorySearchTool::class, $agentTools[0]);
    }

    public function testKanvasGenericAgentInstantiatesDynamicSubAgent(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $type = AgentType::factory()->withAppId($app->id)->create(['provider' => 'laravel']);

        $subAgentRecord = Agent::factory()
            ->withAppId($app->id)
            ->withCompanyId($company->id)
            ->create([
                'agent_type_id' => $type->id,
                'is_sub_agent' => true,
            ]);

        $subAgentTool = new CreateToolAction(
            new ToolData(
                app: $app,
                name: 'sub-agent-tool-' . uniqid(),
                frameworks: ['laravel'],
                toolType: ToolTypeEnum::SUB_AGENT,
            ),
        )->execute();
        $subAgentTool->update(['agents_id' => $subAgentRecord->getId()]);

        $mainAgent = Agent::factory()
            ->withAppId($app->id)
            ->withCompanyId($company->id)
            ->create(['agent_type_id' => $type->id]);

        $mainAgent->selectedTools()->sync([$subAgentTool->getId()]);

        $generic = new KanvasGenericLaravelAgent();
        $generic->setConfiguration($mainAgent);

        $tools = iterator_to_array((function ($t) {
            yield from $t;
        })($generic->agentTools()));

        $this->assertCount(1, $tools);
        $this->assertInstanceOf(DynamicSubAgent::class, $tools[0]);
    }

    public function testUpdateAgentToSubAgentCreatesToolWhenNotExists(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $type = AgentType::factory()->withAppId($app->id)->create(['provider' => 'laravel']);

        $agent = Agent::factory()
            ->withAppId($app->id)
            ->withCompanyId($company->id)
            ->create([
                'agent_type_id' => $type->id,
                'is_sub_agent' => false,
            ]);

        $this->assertFalse(Tool::query()->where('agents_id', $agent->getId())->exists());

        $dto = new AgentData(
            app: $app,
            company: $company,
            user: $user,
            agentType: $type,
            name: $agent->name,
            role: 'assistant',
            is_active: true,
            isSubAgent: true,
            soul: 'You are a helpful sub-agent.',
        );

        $updated = new UpdateAgentAction($dto, $agent)->execute();

        $this->assertTrue($updated->is_sub_agent);
        $this->assertTrue(
            Tool::query()->where('agents_id', $updated->getId())->exists(),
            'Tool record must be created when agent is converted to sub-agent via update.'
        );
        $this->assertSame(
            ToolTypeEnum::SUB_AGENT->value,
            Tool::query()->where('agents_id', $updated->getId())->first()->tool_type
        );
    }

    public function testUpdateSubAgentSyncsToolNameAndDescription(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $type = AgentType::factory()->withAppId($app->id)->create(['provider' => 'laravel']);

        $dto = new AgentData(
            app: $app,
            company: $company,
            user: $user,
            agentType: $type,
            name: 'Original Name',
            role: 'assistant',
            is_active: true,
            isSubAgent: true,
            soul: 'Original soul.',
        );

        $agent = new CreateAgentAction($dto)->execute();
        $tool = Tool::query()->where('agents_id', $agent->getId())->first();
        $this->assertSame('original-name', $tool->name);

        $updateDto = new AgentData(
            app: $app,
            company: $company,
            user: $user,
            agentType: $type,
            name: 'Updated Name',
            role: 'assistant',
            is_active: true,
            isSubAgent: true,
            soul: 'Updated soul.',
        );

        new UpdateAgentAction($updateDto, $agent)->execute();

        $tool->refresh();
        $this->assertSame('updated-name', $tool->name);
        $this->assertSame('Updated soul.', $tool->description);
    }
}
