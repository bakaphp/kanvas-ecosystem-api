<?php

declare(strict_types=1);

namespace Tests\Stubs\Intelligence;

use Generator;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\MessageMapperInterface;
use NeuronAI\Providers\ToolMapperInterface;

class FakeNeuronProvider implements AIProviderInterface
{
    public function __construct(
        private readonly string $response = 'Hola Mundo',
    ) {
    }

    public function systemPrompt(?string $prompt): AIProviderInterface
    {
        return $this;
    }

    public function setTools(array $tools): AIProviderInterface
    {
        return $this;
    }

    public function messageMapper(): MessageMapperInterface
    {
        return new class implements MessageMapperInterface {
            public function map(array $messages): array
            {
                return [];
            }
        };
    }

    public function toolPayloadMapper(): ToolMapperInterface
    {
        return new class implements ToolMapperInterface {
            public function map(array $tools): array
            {
                return [];
            }
        };
    }

    public function chat(Message ...$messages): Message
    {
        return new AssistantMessage($this->response);
    }

    public function stream(Message ...$messages): Generator
    {
        yield new AssistantMessage($this->response);

        return new AssistantMessage($this->response);
    }

    public function structured(array|Message $messages, string $class, array $response_schema): Message
    {
        return new AssistantMessage($this->response);
    }

    public function setHttpClient(HttpClientInterface $client): AIProviderInterface
    {
        return $this;
    }
}
