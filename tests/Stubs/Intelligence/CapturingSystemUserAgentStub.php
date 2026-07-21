<?php

declare(strict_types=1);

namespace Tests\Stubs\Intelligence;

use Kanvas\Intelligence\Agents\Neuron\SystemUserAgent;
use NeuronAI\Chat\History\AbstractChatHistory;
use NeuronAI\Chat\History\InMemoryChatHistory;
use NeuronAI\Providers\AIProviderInterface;
use Override;

/**
 * SystemUserAgent (the @mention teammate archetype) wired to a CapturingNeuronProvider + in-memory
 * history so a test can drive a real mention turn with zero network / zero DB conversation writes and
 * read back exactly which content blocks reached the model via CapturingNeuronProvider::$lastMessages.
 * Must extend SystemUserAgent — RespondToMentionJob gates on `instanceof SystemUserAgent`.
 */
class CapturingSystemUserAgentStub extends SystemUserAgent
{
    #[Override]
    protected function provider(): AIProviderInterface
    {
        return new CapturingNeuronProvider('Hola Sistema');
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
        return 'Capturing mention test agent';
    }
}
