<?php

declare(strict_types=1);

namespace Tests\Stubs\Intelligence;

use Kanvas\Intelligence\Agents\Neuron\ProjectManagement\ProjectManagerAgent;
use NeuronAI\Providers\AIProviderInterface;
use Override;
use Tests\Stubs\Intelligence\Concerns\RunsOffline;

/**
 * The real ProjectManagerAgent (real instructions, real project grounding) on a fake provider and
 * in-memory history, so a wake can run end to end with no network. The static sink is what a test
 * reads back: the wake instantiates the agent deep inside the kernel, out of the test's reach.
 */
class CapturingProjectManagerAgentStub extends ProjectManagerAgent
{
    use RunsOffline;

    public static string $lastInstructions = '';

    #[Override]
    protected function provider(): AIProviderInterface
    {
        return new FakeNeuronProvider('Hola PM');
    }

    #[Override]
    public function instructions(): string
    {
        return self::$lastInstructions = parent::instructions();
    }
}
