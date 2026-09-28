<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentType;
use Kanvas\Intelligence\Agents\Neuron\CRM\SalesAgent;
use Kanvas\Intelligence\Agents\Neuron\KanvasGenericNeuronAgent;
use Kanvas\Intelligence\Agents\Neuron\Tools\DynamicSubAgentTool;
use Kanvas\NervousSystem\Capability\Actions\CreateToolAction;
use Kanvas\NervousSystem\Capability\DataTransferObject\Tool as ToolData;
use Kanvas\NervousSystem\Capability\Enums\ToolTypeEnum;
use Tests\TestCase;

class NeuronDynamicSubAgentTest extends TestCase
{
    use DatabaseTransactions;

    public function testGenericNeuronAgentResolvesAgentBackedTool(): void
    {
        [$mainAgent, $subAgent] = $this->makeAgentPair(KanvasGenericNeuronAgent::class);

        $handler = new class () extends KanvasGenericNeuronAgent {
            public function resolvedTools(): array
            {
                return $this->tools();
            }
        };
        $handler->setConfiguration($mainAgent, user: auth()->user());

        $tools = $handler->resolvedTools();

        $this->assertCount(1, $tools);
        $this->assertInstanceOf(DynamicSubAgentTool::class, $tools[0]);
        $this->assertSame(
            str_replace('-', '_', Str::snake($subAgent->name)),
            str_replace('-', '_', $tools[0]->getName()),
        );
    }

    public function testSalesAgentResolvesAgentBackedToolAlongsideBaselineTools(): void
    {
        [$mainAgent] = $this->makeAgentPair(SalesAgent::class);

        $handler = new class () extends SalesAgent {
            public function resolvedTools(): array
            {
                return $this->tools();
            }
        };
        $handler->setConfiguration($mainAgent, user: auth()->user());

        $this->assertNotEmpty(array_filter(
            $handler->resolvedTools(),
            fn (object $tool): bool => $tool instanceof DynamicSubAgentTool,
        ));
    }

    /**
     * @return array{Agent, Agent}
     */
    private function makeAgentPair(string $handler): array
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        $type = AgentType::factory()
            ->withAppId($app->getId())
            ->create([
                'provider' => 'neuron',
                'handler' => $handler,
            ]);

        $subAgent = Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create([
                'agent_type_id' => $type->getId(),
                'name' => 'First Message Specialist',
                'is_active' => true,
                'is_sub_agent' => true,
            ]);

        $tool = new CreateToolAction(new ToolData(
            app: $app,
            name: 'first-message-specialist',
            frameworks: ['neuron'],
            toolType: ToolTypeEnum::SUB_AGENT,
        ))->execute();
        $tool->update(['agents_id' => $subAgent->getId()]);

        $mainAgent = Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create([
                'agent_type_id' => $type->getId(),
                'is_active' => true,
            ]);
        $mainAgent->selectedTools()->sync([$tool->getId()]);

        return [$mainAgent->refresh(), $subAgent];
    }
}
