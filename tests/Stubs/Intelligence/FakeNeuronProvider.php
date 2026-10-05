<?php

declare(strict_types=1);

namespace Tests\Stubs\Intelligence;

use Generator;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\Stream\Chunks\TextChunk;
use NeuronAI\Chat\Messages\SystemMessage;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\ProviderResponse;
use NeuronAI\Tools\ToolInterface;

/**
 * The smallest provider that satisfies AIProviderInterface: every verb answers with one fixed assistant
 * message and nothing leaves the process. Subclasses override chat() to script a turn.
 */
class FakeNeuronProvider implements AIProviderInterface
{
    public function __construct(
        protected readonly string $response = 'Hola Mundo',
    ) {
    }

    public function getModel(): string
    {
        return 'fake-model';
    }

    public function systemPrompt(SystemMessage|string|null $prompt): AIProviderInterface
    {
        return $this;
    }

    public function setTools(array $tools): AIProviderInterface
    {
        return $this;
    }

    public function chat(Message ...$messages): ProviderResponse
    {
        return $this->respond(new AssistantMessage($this->response));
    }

    public function stream(Message ...$messages): Generator
    {
        $response = $this->chat(...$messages);

        yield new TextChunk($response->message()->getId(), (string) $response->message()->getContent());

        return $response;
    }

    public function structured(array|Message $messages, string $class, array $response_schema): ProviderResponse
    {
        return $this->respond(new AssistantMessage($this->response));
    }

    public function setHttpClient(HttpClientInterface $client): AIProviderInterface
    {
        return $this;
    }

    protected function respond(Message $message): ProviderResponse
    {
        return new ProviderResponse(message: $message);
    }

    protected static function toolName(ToolInterface|string $tool): string
    {
        return $tool instanceof ToolInterface ? $tool->getName() : $tool;
    }
}
