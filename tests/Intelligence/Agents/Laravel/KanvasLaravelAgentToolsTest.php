<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents\Laravel;

use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Factories\AgentFactory;
use Kanvas\Intelligence\Agents\Laravel\KanvasLaravelAgent;
use Kanvas\Intelligence\Agents\Laravel\KanvasSubAgentTool;
use Kanvas\Intelligence\Agents\Laravel\SubAgents\CheckLeadDuplicateSubAgent;
use Kanvas\Intelligence\Agents\Laravel\Tools\Common\CurrentTimeTool;
use Kanvas\Intelligence\Agents\Laravel\Tools\Guild\LeadSearchTool;
use Stringable;
use Tests\TestCase;

final class KanvasLaravelAgentToolsTest extends TestCase
{
    private function agentWith(array $tools, array $config = []): KanvasLaravelAgent
    {
        $agent = new class ($tools) extends KanvasLaravelAgent {
            public function __construct(private readonly array $toolList)
            {
            }

            public function instructions(): Stringable|string
            {
                return 'test';
            }

            public function agentTools(): iterable
            {
                return $this->toolList;
            }
        };

        $record = AgentFactory::new()
            ->withAppId(app(Apps::class)->getId())
            ->withCompanyId(auth()->user()->getCurrentCompany()->getId())
            ->create(['config' => $config]);

        $agent->setConfiguration($record);

        return $agent;
    }

    public function testSubAgentsAreWrappedSoAnEmptyAnswerNeverReachesTheProvider(): void
    {
        $tools = $this->agentWith([new CheckLeadDuplicateSubAgent()])->tools();

        $this->assertInstanceOf(KanvasSubAgentTool::class, $tools[0]);
        $this->assertInstanceOf(CheckLeadDuplicateSubAgent::class, $tools[0]->agent());
        $this->assertInstanceOf(CurrentTimeTool::class, $tools[1]);
    }

    public function testPlainToolsAreHandedOverAsIs(): void
    {
        $tool = new LeadSearchTool();

        $this->assertSame($tool, $this->agentWith([$tool])->tools()[0]);
        $this->assertSame('search_leads', $tool->name());
    }

    public function testStepBudgetComesFromTheAgentConfig(): void
    {
        $this->assertSame(7, $this->agentWith([], ['max_steps' => 7])->maxSteps());
        $this->assertSame(1, $this->agentWith([], ['max_steps' => 0])->maxSteps());
        $this->assertNull($this->agentWith([])->maxSteps());
    }
}
