<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents;

use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Laravel\KanvasGenericLaravelAgent;
use Kanvas\Intelligence\Agents\Laravel\Tools\Inventory\InventorySearchTool;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentType;
use Kanvas\NervousSystem\Capability\Actions\CreateToolAction;
use Kanvas\NervousSystem\Capability\DataTransferObject\Tool as ToolData;
use Kanvas\NervousSystem\Capability\Enums\ToolTypeEnum;
use Tests\TestCase;

class KanvasGenericLaravelAgentTest extends TestCase
{
    public function testInstructionsConcatenatesSoulInstructionsAndOutputFormat(): void
    {
        $agent = $this->makeAgentRecord(
            soul: 'You are a helpful assistant.',
            instructions: 'Step 1: Greet. Step 2: Help.',
            outputFormat: 'Reply in JSON.',
        );

        $generic = new KanvasGenericLaravelAgent();
        $generic->setConfiguration($agent);

        $result = (string) $generic->instructions();

        $this->assertStringContainsString('You are a helpful assistant.', $result);
        $this->assertStringContainsString('Step 1: Greet. Step 2: Help.', $result);
        $this->assertStringContainsString('Reply in JSON.', $result);
    }

    public function testInstructionsSkipsNullParts(): void
    {
        $agent = $this->makeAgentRecord(
            soul: 'Only soul set.',
            instructions: null,
            outputFormat: null,
        );

        $generic = new KanvasGenericLaravelAgent();
        $generic->setConfiguration($agent);

        $result = (string) $generic->instructions();

        $this->assertSame('Only soul set.', $result);
    }

    public function testInstructionsReturnsEmptyStringWhenAllNull(): void
    {
        $agent = $this->makeAgentRecord(
            soul: null,
            instructions: null,
            outputFormat: null,
        );

        $generic = new KanvasGenericLaravelAgent();
        $generic->setConfiguration($agent);

        $this->assertSame('', (string) $generic->instructions());
    }

    public function testAgentToolsReturnsEmptyIterable(): void
    {
        $agent = $this->makeAgentRecord();

        $generic = new KanvasGenericLaravelAgent();
        $generic->setConfiguration($agent);

        $tools = $generic->agentTools();

        $this->assertEmpty(iterator_to_array(
            (function () use ($tools) {
                yield from $tools;
            })()
        ));
    }

    public function testAgentToolsInstantiatesHandlerFromSelectedTool(): void
    {
        $app = app(Apps::class);

        $tool = new CreateToolAction(
            new ToolData(
                app: $app,
                name: 'tool-' . uniqid(),
                frameworks: ['laravel'],
                toolType: ToolTypeEnum::CUSTOM,
                handler: InventorySearchTool::class,
            ),
        )->execute();

        $agent = $this->makeAgentRecord();
        $agent->selectedTools()->sync([$tool->getId()]);

        $generic = new KanvasGenericLaravelAgent();
        $generic->setConfiguration($agent);

        $result = iterator_to_array(
            (function ($tools) {
                yield from $tools;
            })($generic->agentTools())
        );

        $this->assertCount(1, $result);
        $this->assertInstanceOf(InventorySearchTool::class, $result[0]);
    }

    public function testAgentToolsSkipsToolsWithNullOrInvalidHandler(): void
    {
        $app = app(Apps::class);

        $nullHandlerTool = new CreateToolAction(
            new ToolData(
                app: $app,
                name: 'tool-null-' . uniqid(),
                frameworks: ['laravel'],
                toolType: ToolTypeEnum::CUSTOM,
            ),
        )->execute();

        $badHandlerTool = new CreateToolAction(
            new ToolData(
                app: $app,
                name: 'tool-bad-' . uniqid(),
                frameworks: ['laravel'],
                toolType: ToolTypeEnum::CUSTOM,
                handler: 'NonExistent\\Tool\\Class',
            ),
        )->execute();

        $agent = $this->makeAgentRecord();
        $agent->selectedTools()->sync([$nullHandlerTool->getId(), $badHandlerTool->getId()]);

        $generic = new KanvasGenericLaravelAgent();
        $generic->setConfiguration($agent);

        $result = iterator_to_array(
            (function ($tools) {
                yield from $tools;
            })($generic->agentTools())
        );

        $this->assertEmpty($result);
    }

    private function makeAgentRecord(
        ?string $soul = null,
        ?string $instructions = null,
        ?string $outputFormat = null,
    ): Agent {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $type = AgentType::factory()
            ->withAppId($app->id)
            ->create();

        return Agent::factory()
            ->withAppId($app->id)
            ->withCompanyId($company->id)
            ->create([
                'agent_type_id' => $type->id,
                'soul' => $soul,
                'instructions' => $instructions,
                'output_format' => $outputFormat,
            ]);
    }
}
