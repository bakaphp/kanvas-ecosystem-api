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

/**
 * Like FakeNeuronProvider but records the exact Message list it was handed on the last chat() call,
 * so a test can assert which content blocks (image / audio / PDF / text) the runner attached to the
 * outgoing UserMessage — without any network round-trip.
 */
class CapturingNeuronProvider implements AIProviderInterface
{
    /** @var list<Message> */
    public array $messages = [];

    /**
     * Cross-instance sink: a full webhook/job run instantiates the agent (and this provider) deep
     * inside the kernel, so a test can't reach the instance. This static holds the last run's messages
     * regardless of which instance served them. Reset it in the test's setUp.
     *
     * @var list<Message>
     */
    public static array $lastMessages = [];

    public function __construct(
        private readonly string $response = 'Captured reply',
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
        return new class () implements MessageMapperInterface {
            public function map(array $messages): array
            {
                return [];
            }
        };
    }

    public function toolPayloadMapper(): ToolMapperInterface
    {
        return new class () implements ToolMapperInterface {
            public function map(array $tools): array
            {
                return [];
            }
        };
    }

    public function chat(Message ...$messages): Message
    {
        $this->messages = $messages;
        self::$lastMessages = $messages;

        return new AssistantMessage($this->response);
    }

    public function stream(Message ...$messages): Generator
    {
        $this->messages = $messages;
        self::$lastMessages = $messages;

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
