<?php

declare(strict_types=1);

namespace Tests\Stubs\Intelligence;

use Kanvas\Intelligence\Agents\Neuron\KanvasGenericNeuronAgent;
use NeuronAI\Providers\AIProviderInterface;
use Override;

class PricedReplyNeuronAgentStub extends KanvasGenericNeuronAgent
{
    public const string REPLY = 'Great news, the Sierra is $54,995 out the door!';

    #[Override]
    protected function provider(): AIProviderInterface
    {
        return new FakeNeuronProvider(self::REPLY);
    }

    #[Override]
    public function instructions(): string
    {
        return (string) $this->agent?->instructions;
    }
}
