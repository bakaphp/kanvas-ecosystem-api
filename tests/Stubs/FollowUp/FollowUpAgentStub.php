<?php

declare(strict_types=1);

namespace Tests\Stubs\FollowUp;

use Generator;
use Kanvas\Intelligence\Agents\Neuron\CRM\FollowUpAgent;
use NeuronAI\Chat\History\AbstractChatHistory;
use NeuronAI\Chat\History\InMemoryChatHistory;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\MessageMapperInterface;
use NeuronAI\Providers\ToolMapperInterface;
use Override;

/**
 * Test double for the real FollowUpAgent. Returns a canned JSON response
 * configured per-test via the static $cannedResponse property — bypassing
 * the real Gemini call.
 *
 * Wired by creating an agent_types row with handler = this class, and an
 * Agent named AgentEnum::FOLLOW_UP_ENGAGER pointing at that type. The kernel
 * routes through the standard Neuron path; the only difference is the
 * provider, which returns the canned string instead of hitting an LLM.
 *
 * Chat history is short-circuited to InMemoryChatHistory so tests don't
 * need to seed messages just to exercise the agent decision.
 */
class FollowUpAgentStub extends FollowUpAgent
{
    /**
     * The JSON string the stub will return as the assistant message.
     * Tests configure this BEFORE invoking the action / kernel.
     *
     * Default is a "skip" — should_respond=false, no advance — which is
     * the safest no-op shape so tests that forget to configure don't
     * accidentally trigger sends.
     */
    public static string $cannedResponse = '{"should_respond": false, "advance_stage": false, "message": null, "reason": "stub-default"}';

    /**
     * Captures the last batch of messages the provider was asked to chat on.
     * Tests inspect this to assert prompt content (humanized silence,
     * template-as-style-reference, etc.).
     *
     * @var Message[]
     */
    public static array $lastReceivedMessages = [];

    public static function reset(): void
    {
        self::$cannedResponse = '{"should_respond": false, "advance_stage": false, "message": null, "reason": "stub-default"}';
        self::$lastReceivedMessages = [];
    }

    public static function lastPromptText(): string
    {
        $parts = [];
        foreach (self::$lastReceivedMessages as $m) {
            $content = $m->getContent();
            if (is_string($content)) {
                $parts[] = $content;
            }
        }

        return implode("\n", $parts);
    }

    public static function configure(
        bool $shouldRespond = false,
        bool $advanceStage = false,
        ?string $message = null,
        string $reason = 'stub-configured',
    ): void {
        self::$cannedResponse = (string) json_encode([
            'should_respond' => $shouldRespond,
            'advance_stage' => $advanceStage,
            'message' => $message,
            'reason' => $reason,
        ]);
    }

    #[Override]
    protected function provider(): AIProviderInterface
    {
        return new class (self::$cannedResponse) implements AIProviderInterface {
            public function __construct(private readonly string $response)
            {
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
                FollowUpAgentStub::$lastReceivedMessages = $messages;

                return new AssistantMessage($this->response);
            }

            public function stream(Message ...$messages): Generator
            {
                FollowUpAgentStub::$lastReceivedMessages = $messages;
                yield new AssistantMessage($this->response);

                return new AssistantMessage($this->response);
            }

            public function structured(array|Message $messages, string $class, array $response_schema): Message
            {
                FollowUpAgentStub::$lastReceivedMessages = is_array($messages) ? $messages : [$messages];

                return new AssistantMessage($this->response);
            }

            public function setHttpClient(HttpClientInterface $client): AIProviderInterface
            {
                return $this;
            }
        };
    }

    #[Override]
    protected function chatHistory(): AbstractChatHistory
    {
        return new InMemoryChatHistory();
    }

    #[Override]
    public function instructions(): string
    {
        // Skip the Blade-rendered role + JSON contract — irrelevant for tests
        // that pre-stamp the response. Returning a non-empty string keeps the
        // NeuronAI library happy.
        return 'follow-up-agent-stub';
    }
}
