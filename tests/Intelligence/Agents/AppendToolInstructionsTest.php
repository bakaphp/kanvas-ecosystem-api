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

        $this->assertStringNotContainsString('## Tool Usage Guidelines', $agent->fresh()->instructions ?? '');
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
}
