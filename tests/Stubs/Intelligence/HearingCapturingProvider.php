<?php

declare(strict_types=1);

namespace Tests\Stubs\Intelligence;

use Kanvas\Intelligence\Agents\Neuron\Providers\KanvasGemini;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Providers\ProviderResponse;
use Override;

/**
 * A Gemini-shaped provider that never goes to the network: it is what the runner sees as a model
 * that can listen, so a test can prove an audio block is kept rather than transcribed.
 */
class HearingCapturingProvider extends KanvasGemini
{
    /** @var list<Message> */
    public array $messages = [];

    public function __construct(private readonly string $response = 'Captured reply')
    {
        parent::__construct(key: 'test', model: 'gemini-test');
    }

    #[Override]
    public function chat(Message ...$messages): ProviderResponse
    {
        $this->messages = $messages;

        return new ProviderResponse(message: new AssistantMessage($this->response));
    }
}
