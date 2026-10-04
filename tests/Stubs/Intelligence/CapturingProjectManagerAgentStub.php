<?php

declare(strict_types=1);

namespace Tests\Stubs\Intelligence;

use Kanvas\Intelligence\Agents\Neuron\ProjectManagement\ProjectManagerAgent;
use NeuronAI\Chat\History\InMemoryMessageStore;
use NeuronAI\Chat\History\MessageStoreInterface;
use NeuronAI\Providers\AIProviderInterface;
use Override;

/**
 * The real ProjectManagerAgent (real instructions, real project grounding) on a fake provider and
 * in-memory history, so a wake can run end to end with no network. The static sink is what a test
 * reads back: the wake instantiates the agent deep inside the kernel, out of the test's reach.
 */
class CapturingProjectManagerAgentStub extends ProjectManagerAgent
{
    public static string $lastInstructions = '';

    #[Override]
    protected function provider(): AIProviderInterface
    {
        return new FakeNeuronProvider('Hola PM');
    }

    #[Override]
    protected function messageStore(): MessageStoreInterface
    {
        return new InMemoryMessageStore();
    }

    /**
     * @return list<object>
     */
    #[Override]
    protected function tools(): array
    {
        return [];
    }

    #[Override]
    public function instructions(): string
    {
        return self::$lastInstructions = parent::instructions();
    }
}
