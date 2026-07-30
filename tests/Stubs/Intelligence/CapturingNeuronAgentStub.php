<?php

declare(strict_types=1);

namespace Tests\Stubs\Intelligence;

use Kanvas\Intelligence\Agents\Neuron\KanvasGenericNeuronAgent;
use NeuronAI\Chat\History\AbstractChatHistory;
use NeuronAI\Chat\History\InMemoryChatHistory;
use NeuronAI\Providers\AIProviderInterface;
use Override;

/**
 * Neuron agent wired to a CapturingNeuronProvider and an in-memory history so a test can drive a real
 * turn (attachments → content blocks) with zero network and zero DB conversation writes, then read
 * back exactly what got sent to the model via $this->capturedProvider->messages.
 */
class CapturingNeuronAgentStub extends KanvasGenericNeuronAgent
{
    public ?CapturingNeuronProvider $capturedProvider = null;

    #[Override]
    protected function provider(): AIProviderInterface
    {
        return $this->capturedProvider ??= new CapturingNeuronProvider();
    }

    #[Override]
    protected function chatHistory(): AbstractChatHistory
    {
        return new InMemoryChatHistory();
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
        return 'Capturing test agent';
    }
}
