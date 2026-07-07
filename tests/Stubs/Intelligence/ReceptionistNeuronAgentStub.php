<?php

declare(strict_types=1);

namespace Tests\Stubs\Intelligence;

use Kanvas\Intelligence\Agents\Neuron\CRM\ReceptionistAgent;
use NeuronAI\Providers\AIProviderInterface;
use Override;

/**
 * Real ReceptionistAgent (its instructions + full tool set) with the LLM swapped for a
 * deterministic fake provider, so the channel → agent → reply path can be exercised
 * end-to-end without touching a real model. Mirrors SalesNeuronAgentStub.
 */
class ReceptionistNeuronAgentStub extends ReceptionistAgent
{
    #[Override]
    protected function provider(): AIProviderInterface
    {
        return new FakeNeuronProvider('Hola, gracias por escribir. ¿En qué le puedo ayudar hoy?');
    }
}
