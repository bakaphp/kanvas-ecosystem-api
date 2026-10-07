<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents\Memory;

use Kanvas\Intelligence\Agents\Neuron\KanvasGenericNeuronAgent;
use NeuronAI\Tools\ToolInterface;
use Tests\Stubs\Intelligence\RememberingSystemUserAgentStub;
use Tests\TestCase;
use Tests\Traits\MakesAgents;

class MemoryToolsOnRememberingAgentsTest extends TestCase
{
    use MakesAgents;

    public function testARememberingAgentCanSearchItsKnowledgeAndMemory(): void
    {
        $user = auth()->user();
        $handler = new RememberingSystemUserAgentStub();
        $handler->setConfiguration(agent: $this->makeAgentFor($user), entity: $user, user: $user);

        $this->assertContains('search_knowledge', $this->names($handler->getTools()));
    }

    public function testAnAgentThatDoesNotRememberGetsNoSearchTool(): void
    {
        $user = auth()->user();
        $handler = new KanvasGenericNeuronAgent();
        $handler->setConfiguration(agent: $this->makeAgentFor($user), user: $user);

        $this->assertNotContains('search_knowledge', $this->names($handler->getTools()), 'No memory and no knowledge: nothing to search');
    }

    /**
     * @return list<string>
     */
    private function names(iterable $tools): array
    {
        $names = [];
        foreach ($tools as $tool) {
            if ($tool instanceof ToolInterface) {
                $names[] = $tool->getName();
            }
        }

        return $names;
    }
}
