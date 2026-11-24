<?php

declare(strict_types=1);

namespace Tests\Stubs\Intelligence;

use Kanvas\Intelligence\Agents\Neuron\KanvasGenericNeuronAgent;
use NeuronAI\Providers\AIProviderInterface;
use Override;
use Tests\Stubs\Intelligence\Concerns\RunsOffline;

/**
 * CapturingNeuronAgentStub on a provider the runner treats as able to hear audio.
 */
class HearingCapturingNeuronAgentStub extends KanvasGenericNeuronAgent
{
    use RunsOffline;

    public ?HearingCapturingProvider $capturedProvider = null;

    #[Override]
    protected function provider(): AIProviderInterface
    {
        return $this->capturedProvider ??= new HearingCapturingProvider();
    }

    #[Override]
    public function instructions(): string
    {
        return 'Hearing capturing test agent';
    }
}
