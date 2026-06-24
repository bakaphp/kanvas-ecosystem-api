<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents;

use App\GraphQL\Intelligence\Mutations\AgentManagementMutation;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Laravel\Tools\FinancialModelingPrep\FmpCompanyNewsTool;
use Kanvas\Intelligence\Agents\Laravel\Tools\FinancialModelingPrep\FmpCompanyProfileTool;
use Kanvas\Intelligence\Agents\Laravel\Tools\FinancialModelingPrep\FmpCompanyRatingTool;
use Kanvas\Intelligence\Agents\Laravel\Tools\FinancialModelingPrep\FmpCompanySearchTool;
use Kanvas\Intelligence\Agents\Laravel\Tools\FinancialModelingPrep\FmpFinancialRatiosTool;
use Kanvas\Intelligence\Agents\Laravel\Tools\FinancialModelingPrep\FmpFinancialSnapshotTool;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentType;
use Kanvas\NervousSystem\Capability\Actions\CreateToolAction;
use Kanvas\NervousSystem\Capability\DataTransferObject\Tool as ToolData;
use Kanvas\NervousSystem\Capability\Enums\ToolTypeEnum;
use Kanvas\NervousSystem\Capability\Models\Tool;
use Tests\TestCase;

final class AppendToolInstructionsTest extends TestCase
{
    use DatabaseTransactions;

    private const FMP_HANDLERS = [
        FmpCompanySearchTool::class,
        FmpCompanyProfileTool::class,
        FmpCompanyNewsTool::class,
        FmpCompanyRatingTool::class,
        FmpFinancialRatiosTool::class,
    ];

    private function makeAgentType(?string $instructions = null): AgentType
    {
        return AgentType::factory()
            ->withAppId(app(Apps::class)->id)
            ->create(['instructions' => $instructions]);
    }

    private function makeFmpTools(): array
    {
        $tools = [];

        foreach (self::FMP_HANDLERS as $handler) {
            $tools[] = new CreateToolAction(new ToolData(
                app: app(Apps::class),
                name: 'fmp-' . uniqid(),
                frameworks: ['laravel'],
                toolType: ToolTypeEnum::CUSTOM,
                handler: $handler,
            ))->execute();
        }

        return $tools;
    }

    private function attachTools(AgentType $agentType, array $tools): void
    {
        foreach ($tools as $tool) {
            $agentType->tools()->attach($tool->getId());
        }
    }

    private function createAgentViaMutation(AgentType $agentType, array $extraInput = []): Agent
    {
        return new AgentManagementMutation()->create(null, [
            'input' => array_merge([
                'agent_type_id' => $agentType->getId(),
                'name' => 'Test Agent ' . uniqid(),
                'is_active' => true,
                'role' => [],
                'config' => [],
            ], $extraInput),
        ]);
    }

    public function testAllFmpToolInstructionsAppendedWhenNoToolIdsProvided(): void
    {
        $agentType = $this->makeAgentType();
        $this->attachTools($agentType, $this->makeFmpTools());

        $agent = $this->createAgentViaMutation($agentType);

        $this->assertNotNull($agent->instructions);
        $this->assertStringContainsString('## Tool Usage Guidelines', $agent->instructions);
        $this->assertStringContainsString('FMP Company Search', $agent->instructions);
        $this->assertStringContainsString('FMP Company Profile', $agent->instructions);
        $this->assertStringContainsString('FMP Company News', $agent->instructions);
        $this->assertStringContainsString('FMP Company Rating', $agent->instructions);
        $this->assertStringContainsString('FMP Financial Ratios', $agent->instructions);

        $this->assertNotNull($agent->tool_usage, 'tool_usage must be populated when tools are assigned.');
        $this->assertStringNotContainsString('## Tool Usage Guidelines', $agent->tool_usage, 'tool_usage must not contain the section header.');
        $this->assertStringContainsString('FMP Company Search', $agent->tool_usage);
        $this->assertStringContainsString('FMP Company Profile', $agent->tool_usage);
        $this->assertStringContainsString('FMP Financial Ratios', $agent->tool_usage);
    }

    public function testToolGuidelinesSectionAppearsExactlyOnce(): void
    {
        $agentType = $this->makeAgentType();
        $this->attachTools($agentType, $this->makeFmpTools());

        $agent = $this->createAgentViaMutation($agentType, ['instructions' => 'My own instructions.']);

        $this->assertSame(
            1,
            substr_count($agent->instructions, '## Tool Usage Guidelines'),
            'Expected exactly one ## Tool Usage Guidelines section.'
        );
    }

    public function testToolGuidelinesSectionAppearsExactlyOnceAfterUpdate(): void
    {
        $agentType = $this->makeAgentType();
        $tools = $this->makeFmpTools();
        $this->attachTools($agentType, $tools);

        $agent = $this->createAgentViaMutation($agentType, ['instructions' => 'My own instructions.']);

        $updated = new AgentManagementMutation()->update(null, [
            'id' => $agent->getId(),
            'input' => [
                'agent_type_id' => $agentType->getId(),
                'name' => $agent->name,
                'is_active' => true,
                'role' => [],
                'config' => [],
                'instructions' => 'My own instructions.',
                'tool_ids' => array_map(fn (Tool $t) => $t->getId(), $tools),
            ],
        ]);

        $this->assertSame(
            1,
            substr_count($updated->instructions, '## Tool Usage Guidelines'),
            'Update must not duplicate the ## Tool Usage Guidelines section.'
        );
    }

    public function testUpdateWithoutToolIdsDoesNotAppendToolGuidelines(): void
    {
        $agentType = $this->makeAgentType();

        $agent = $this->createAgentViaMutation($agentType, ['instructions' => 'My own instructions.']);
        $this->assertStringNotContainsString('## Tool Usage Guidelines', $agent->instructions ?? '');
        $this->assertNull($agent->tool_usage, 'tool_usage must be null when no tools are assigned.');

        new AgentManagementMutation()->update(null, [
            'id' => $agent->getId(),
            'input' => [
                'agent_type_id' => $agentType->getId(),
                'name' => $agent->name . ' renamed',
                'is_active' => true,
                'role' => [],
                'config' => [],
                'instructions' => 'My own instructions.',
            ],
        ]);

        $fresh = $agent->fresh();
        $this->assertStringNotContainsString('## Tool Usage Guidelines', $fresh->instructions ?? '');
        $this->assertNull($fresh->tool_usage, 'tool_usage must remain null after update without tools.');
    }

    public function testUpdateRegeneratesInstructionsWhenNewToolAdded(): void
    {
        $agentType = $this->makeAgentType();
        $initialTools = array_slice($this->makeFmpTools(), 0, 2);
        $this->attachTools($agentType, $initialTools);

        $agent = $this->createAgentViaMutation($agentType);
        $this->assertStringNotContainsString('FMP Company Rating', $agent->instructions ?? '');

        $ratingTool = new CreateToolAction(new ToolData(
            app: app(Apps::class),
            name: 'fmp-rating-' . uniqid(),
            frameworks: ['laravel'],
            toolType: ToolTypeEnum::CUSTOM,
            handler: FmpCompanyRatingTool::class,
        ))->execute();

        $allIds = array_merge(
            array_map(fn (Tool $t) => $t->getId(), $initialTools),
            [$ratingTool->getId()]
        );

        $updated = new AgentManagementMutation()->update(null, [
            'id' => $agent->getId(),
            'input' => [
                'agent_type_id' => $agentType->getId(),
                'name' => $agent->name,
                'is_active' => true,
                'role' => [],
                'config' => [],
                'tool_ids' => $allIds,
            ],
        ]);

        $this->assertStringContainsString('FMP Company Rating', $updated->fresh()->instructions);
        $this->assertSame(1, substr_count($updated->fresh()->instructions, '## Tool Usage Guidelines'));
    }

    public function testUpdateAddToolAppearsInSelectedTools(): void
    {
        $agentType = $this->makeAgentType();
        $tools = array_slice($this->makeFmpTools(), 0, 2);

        $agent = $this->createAgentViaMutation($agentType, [
            'tool_ids' => array_map(fn (Tool $t) => $t->getId(), $tools),
        ]);

        $this->assertCount(2, $agent->fresh()->selectedTools);

        $newTool = new CreateToolAction(new ToolData(
            app: app(Apps::class),
            name: 'fmp-new-' . uniqid(),
            frameworks: ['laravel'],
            toolType: ToolTypeEnum::CUSTOM,
            handler: FmpCompanyRatingTool::class,
        ))->execute();

        $allIds = array_merge(
            array_map(fn (Tool $t) => $t->getId(), $tools),
            [$newTool->getId()]
        );

        $updated = new AgentManagementMutation()->update(null, [
            'id' => $agent->getId(),
            'input' => [
                'agent_type_id' => $agentType->getId(),
                'name' => $agent->name,
                'is_active' => true,
                'role' => [],
                'config' => [],
                'tool_ids' => $allIds,
            ],
        ]);

        $this->assertCount(3, $updated->fresh()->selectedTools);
        $this->assertTrue(
            $updated->fresh()->selectedTools->contains('id', $newTool->getId()),
            'Newly added tool must appear in selectedTools after update.'
        );
    }

    public function testUpdateRemoveToolDisappearsFromSelectedTools(): void
    {
        $agentType = $this->makeAgentType();
        $tools = array_slice($this->makeFmpTools(), 0, 3);

        $agent = $this->createAgentViaMutation($agentType, [
            'tool_ids' => array_map(fn (Tool $t) => $t->getId(), $tools),
        ]);

        $this->assertCount(3, $agent->fresh()->selectedTools);

        $toolToRemove = $tools[2];
        $remainingIds = array_map(fn (Tool $t) => $t->getId(), array_slice($tools, 0, 2));

        $updated = new AgentManagementMutation()->update(null, [
            'id' => $agent->getId(),
            'input' => [
                'agent_type_id' => $agentType->getId(),
                'name' => $agent->name,
                'is_active' => true,
                'role' => [],
                'config' => [],
                'tool_ids' => $remainingIds,
            ],
        ]);

        $fresh = $updated->fresh();
        $this->assertCount(2, $fresh->selectedTools);
        $this->assertFalse(
            $fresh->selectedTools->contains('id', $toolToRemove->getId()),
            'Removed tool must not appear in selectedTools after update.'
        );
    }

    public function testUpdateRemoveToolInstructionsAlsoRemoved(): void
    {
        $agentType = $this->makeAgentType();
        $tools = $this->makeFmpTools();

        $agent = $this->createAgentViaMutation($agentType, [
            'tool_ids' => array_map(fn (Tool $t) => $t->getId(), $tools),
        ]);

        $this->assertStringContainsString('FMP Financial Ratios', $agent->instructions);

        $withoutRatios = array_filter(
            $tools,
            fn (Tool $t) => $t->handler !== FmpFinancialRatiosTool::class
        );

        $updated = new AgentManagementMutation()->update(null, [
            'id' => $agent->getId(),
            'input' => [
                'agent_type_id' => $agentType->getId(),
                'name' => $agent->name,
                'is_active' => true,
                'role' => [],
                'config' => [],
                'tool_ids' => array_map(fn (Tool $t) => $t->getId(), $withoutRatios),
            ],
        ]);

        $fresh = $updated->fresh();
        $this->assertStringNotContainsString(
            'FMP Financial Ratios',
            $fresh->instructions ?? '',
            'Removed tool instruction must not appear after update.'
        );
        $this->assertSame(1, substr_count($fresh->instructions, '## Tool Usage Guidelines'));
        $this->assertStringNotContainsString('FMP Financial Ratios', $fresh->tool_usage ?? '', 'Removed tool must not appear in tool_usage.');
        $this->assertStringNotContainsString('## Tool Usage Guidelines', $fresh->tool_usage ?? '', 'tool_usage must not contain the section header.');
    }

    public function testAgentTypeInstructionsUsedAsBaseWhenAgentHasNone(): void
    {
        $agentType = $this->makeAgentType('These are the agent type base instructions.');
        $this->attachTools($agentType, $this->makeFmpTools());

        $agent = $this->createAgentViaMutation($agentType);

        $this->assertStringContainsString('These are the agent type base instructions.', $agent->instructions);
        $this->assertStringContainsString('## Tool Usage Guidelines', $agent->instructions);
        $this->assertLessThan(
            strpos($agent->instructions, '## Tool Usage Guidelines'),
            strpos($agent->instructions, 'These are the agent type base instructions.'),
            'Agent type instructions must appear before tool guidelines.'
        );
    }

    public function testUserInstructionsTakePrecedenceOverAgentTypeAsBase(): void
    {
        $agentType = $this->makeAgentType('Agent type instructions.');
        $this->attachTools($agentType, $this->makeFmpTools());

        $agent = $this->createAgentViaMutation($agentType, ['instructions' => 'My own instructions.']);

        $this->assertStringContainsString('My own instructions.', $agent->instructions);
        $this->assertStringContainsString('## Tool Usage Guidelines', $agent->instructions);
        $this->assertStringNotContainsString('Agent type instructions.', $agent->instructions);
    }

    public function testAddingFmpFinancialSnapshotToolAppendsItsInstruction(): void
    {
        $agentType = $this->makeAgentType();
        $initialTool = new CreateToolAction(new ToolData(
            app: app(Apps::class),
            name: 'fmp-search-' . uniqid(),
            frameworks: ['laravel'],
            toolType: ToolTypeEnum::CUSTOM,
            handler: FmpCompanySearchTool::class,
        ))->execute();

        $agent = $this->createAgentViaMutation($agentType, [
            'instructions' => 'Analyze corporate distress events.',
            'tool_ids' => [$initialTool->getId()],
        ]);

        $this->assertStringContainsString('FMP Company Search', $agent->instructions);
        $this->assertStringNotContainsString('FMP Financial Snapshot', $agent->instructions);

        $snapshotTool = new CreateToolAction(new ToolData(
            app: app(Apps::class),
            name: 'fmp-snapshot-' . uniqid(),
            frameworks: ['laravel'],
            toolType: ToolTypeEnum::CUSTOM,
            handler: FmpFinancialSnapshotTool::class,
        ))->execute();

        $updated = new AgentManagementMutation()->update(null, [
            'id' => $agent->getId(),
            'input' => [
                'agent_type_id' => $agentType->getId(),
                'name' => $agent->name,
                'is_active' => true,
                'role' => [],
                'config' => [],
                'tool_ids' => [$initialTool->getId(), $snapshotTool->getId()],
            ],
        ]);

        $fresh = $updated->fresh();
        $this->assertStringContainsString('FMP Financial Snapshot', $fresh->instructions, 'FmpFinancialSnapshotTool instruction must appear after being added.');
        $this->assertStringContainsString('FMP Company Search', $fresh->instructions, 'Previously assigned tool instruction must be preserved.');
        $this->assertStringContainsString('Analyze corporate distress events.', $fresh->instructions, 'Custom base instructions must be preserved when tool_ids is updated without passing instructions.');
        $this->assertSame(1, substr_count($fresh->instructions, '## Tool Usage Guidelines'), 'Tool guidelines section must appear exactly once.');

        $this->assertNotNull($fresh->tool_usage, 'tool_usage must be populated after adding tools.');
        $this->assertStringContainsString('FMP Financial Snapshot', $fresh->tool_usage, 'tool_usage must contain the new tool.');
        $this->assertStringContainsString('FMP Company Search', $fresh->tool_usage, 'tool_usage must contain the previously assigned tool.');
        $this->assertStringNotContainsString('## Tool Usage Guidelines', $fresh->tool_usage, 'tool_usage must not contain the section header.');
    }
}
