<?php

declare(strict_types=1);

namespace Tests\Stubs\FollowUp;

use Kanvas\Intelligence\Agents\Neuron\CRM\FollowUpAgent;
use NeuronAI\Chat\History\AbstractChatHistory;
use NeuronAI\Chat\History\InMemoryChatHistory;
use NeuronAI\Providers\AIProviderInterface;
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

    public static function reset(): void
    {
        self::$cannedResponse = '{"should_respond": false, "advance_stage": false, "message": null, "reason": "stub-default"}';
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
        return new FakeNeuronProvider(self::$cannedResponse);
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
