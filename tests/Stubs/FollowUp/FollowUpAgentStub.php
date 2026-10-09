<?php

declare(strict_types=1);

namespace Tests\Stubs\FollowUp;

use Kanvas\Intelligence\Agents\Neuron\CRM\FollowUpAgent;
use NeuronAI\Chat\History\InMemoryMessageStore;
use NeuronAI\Chat\History\MessageStoreInterface;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\SystemMessage;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\ProviderResponse;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\Retrieval\RetrievalInterface;
use NeuronAI\RAG\VectorStore\Filter\FilterExpression;
use Override;
use Tests\Stubs\Intelligence\FakeNeuronProvider;

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
 * The message store is short-circuited to InMemoryMessageStore so tests don't
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

    /**
     * When non-null, the inline provider throws this on chat() — simulates a
     * provider-level failure (Gemini safety filter, timeout, etc.) so we can
     * exercise the RunNeuronChatAction catch + fallback path end-to-end.
     */
    public static ?\Throwable $throwOnChat = null;

    /**
     * The thread the kernel bound. Its shape answers the rollup-vs-session question: the entity uuid on a
     * channel turn, the session uuid in userChat.
     */
    public static ?string $lastThreadId = null;

    public static ?bool $lastPrivateUserTurn = null;

    /** @var list<Document> */
    public static array $knowledgeDocuments = [];

    public static int $retrievalCalls = 0;
    public static ?string $lastSystemPrompt = null;

    public static function reset(): void
    {
        self::$cannedResponse = '{"should_respond": false, "advance_stage": false, "message": null, "reason": "stub-default"}';
        self::$lastReceivedMessages = [];
        self::$throwOnChat = null;
        self::$knowledgeDocuments = [];
        self::$retrievalCalls = 0;
        self::$lastSystemPrompt = null;
        self::$lastThreadId = null;
        self::$lastPrivateUserTurn = null;
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
        return new class (self::$cannedResponse) extends FakeNeuronProvider {
            #[Override]
            public function systemPrompt(SystemMessage|string|null $prompt): AIProviderInterface
            {
                FollowUpAgentStub::$lastSystemPrompt = $prompt instanceof SystemMessage
                    ? (string) $prompt->getContent()
                    : $prompt;

                return $this;
            }

            #[Override]
            public function chat(Message ...$messages): ProviderResponse
            {
                FollowUpAgentStub::$lastReceivedMessages = $messages;
                if (FollowUpAgentStub::$throwOnChat !== null) {
                    throw FollowUpAgentStub::$throwOnChat;
                }

                return $this->respond(new AssistantMessage($this->response));
            }

            #[Override]
            public function structured(array|Message $messages, string $class, array $response_schema): ProviderResponse
            {
                FollowUpAgentStub::$lastReceivedMessages = is_array($messages) ? $messages : [$messages];

                return $this->respond(new AssistantMessage($this->response));
            }
        };
    }

    #[Override]
    protected function retrieval(): RetrievalInterface
    {
        return new class () implements RetrievalInterface {
            public function retrieve(Message $query, ?FilterExpression $filters = null): array
            {
                FollowUpAgentStub::$retrievalCalls++;

                return FollowUpAgentStub::$knowledgeDocuments;
            }
        };
    }

    #[Override]
    protected function messageStore(): MessageStoreInterface
    {
        return new InMemoryMessageStore();
    }

    #[Override]
    public function setPrivateUserTurn(bool $private): void
    {
        self::$lastPrivateUserTurn = $private;

        parent::setPrivateUserTurn($private);
    }

    #[Override]
    public function setThreadId(string $threadId): static
    {
        self::$lastThreadId = $threadId;

        return parent::setThreadId($threadId);
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
