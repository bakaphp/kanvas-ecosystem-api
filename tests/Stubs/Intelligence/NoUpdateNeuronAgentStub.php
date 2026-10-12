<?php

declare(strict_types=1);

namespace Tests\Stubs\Intelligence;

use Kanvas\Intelligence\Agents\Neuron\KanvasGenericNeuronAgent;
use Kanvas\Intelligence\Agents\Services\AgentTurnResponse;
use NeuronAI\Providers\AIProviderInterface;
use Override;

/** An agent that declines to post, and records what it was asked in CapturingNeuronProvider::$lastMessages. */
class NoUpdateNeuronAgentStub extends KanvasGenericNeuronAgent
{
    #[Override]
    protected function provider(): AIProviderInterface
    {
        return new CapturingNeuronProvider(AgentTurnResponse::NO_UPDATE);
    }

    #[Override]
    public function instructions(): string
    {
        return 'No-update test agent';
    }
}
