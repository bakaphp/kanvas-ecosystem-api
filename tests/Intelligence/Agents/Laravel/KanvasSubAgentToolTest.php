<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents\Laravel;

use Kanvas\Intelligence\Agents\Laravel\KanvasAgentAsTool;
use Kanvas\Intelligence\Agents\Laravel\KanvasSubAgentTool;
use Kanvas\Intelligence\Agents\Laravel\SubAgents\CheckLeadDuplicateSubAgent;
use Laravel\Ai\Tools\Request;
use Tests\TestCase;

final class KanvasSubAgentToolTest extends TestCase
{
    public function testEmptySubAgentAnswerBecomesAnExplicitNoResult(): void
    {
        CheckLeadDuplicateSubAgent::fake(['']);

        $result = new KanvasSubAgentTool(new CheckLeadDuplicateSubAgent())
            ->handle(new Request(['task' => 'Is this event already a lead?']));

        $this->assertSame(KanvasSubAgentTool::NO_ANSWER, $result);
    }

    public function testSubAgentAnswerPassesThroughUntouched(): void
    {
        CheckLeadDuplicateSubAgent::fake(['{"is_duplicate": false, "lead_id": null}']);

        $result = new KanvasSubAgentTool(new CheckLeadDuplicateSubAgent())
            ->handle(new Request(['task' => 'Is this event already a lead?']));

        $this->assertSame('{"is_duplicate": false, "lead_id": null}', $result);
    }

    public function testWrapperKeepsTheSubAgentIdentity(): void
    {
        $subAgent = new CheckLeadDuplicateSubAgent();
        $tool = new KanvasSubAgentTool($subAgent);

        $this->assertSame('check_lead_duplicate', $tool->name());
        $this->assertSame((string) $subAgent->description(), (string) $tool->description());
        $this->assertSame($subAgent, $tool->agent());
    }

    public function testSubAgentsGetAStepBudgetThatOutlivesTheirToolCalls(): void
    {
        $this->assertSame(KanvasAgentAsTool::DEFAULT_MAX_STEPS, new CheckLeadDuplicateSubAgent()->maxSteps());
        $this->assertGreaterThanOrEqual(3, new CheckLeadDuplicateSubAgent()->maxSteps());
    }

    public function testNestedSubAgentsAreWrappedToo(): void
    {
        $parent = new class () extends KanvasAgentAsTool {
            public function name(): string
            {
                return 'parent';
            }

            public function description(): string
            {
                return 'parent';
            }

            public function instructions(): string
            {
                return 'parent';
            }

            public function agentTools(): iterable
            {
                return [new CheckLeadDuplicateSubAgent()];
            }
        };

        $tools = $parent->tools();

        $this->assertInstanceOf(KanvasSubAgentTool::class, $tools[0]);
        $this->assertInstanceOf(CheckLeadDuplicateSubAgent::class, $tools[0]->agent());
    }
}
