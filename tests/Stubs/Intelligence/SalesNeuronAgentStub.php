<?php

declare(strict_types=1);

namespace Tests\Stubs\Intelligence;

use Kanvas\Intelligence\Agents\Neuron\KanvasGenericNeuronAgent;
use NeuronAI\Providers\AIProviderInterface;
use Override;

class SalesNeuronAgentStub extends KanvasGenericNeuronAgent
{
    #[Override]
    protected function provider(): AIProviderInterface
    {
        return new FakeNeuronProvider('Hola Mundo');
    }

    #[Override]
    public function instructions(): string
    {
        return implode("\n\n", array_filter([
            $this->agent?->soul,
            $this->agent?->instructions,
            $this->agent?->output_format,
        ]));
    }
}
