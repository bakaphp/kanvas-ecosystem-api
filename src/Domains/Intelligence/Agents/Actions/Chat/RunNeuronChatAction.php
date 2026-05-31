<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Actions\Chat;

use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Helpers\ChatHelper;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Services\KanvasConversationStore;
use Kanvas\Intelligence\Sessions\Models\Session;
use Kanvas\Users\Models\Users;
use NeuronAI\Agent\AgentHandler;
use NeuronAI\Agent\AgentState;
use NeuronAI\Chat\Enums\SourceType;
use NeuronAI\Chat\Messages\ContentBlocks\ImageContent;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolCallMessage;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Tools\ToolInterface;
use Throwable;

class RunNeuronChatAction
{
    public function __construct(
        protected readonly Agent $agent,
        protected readonly ?Session $session,
        protected readonly string $message,
        protected readonly Apps $app,
        protected readonly Users $user,
        protected readonly mixed $handler,
        protected readonly array $images = []
    ) {
    }

    public function execute(): string
    {
        $sessionId = $this->session?->uuid ?? '';

        $userMessage = new UserMessage($this->message);
        foreach ($this->images as $image) {
            $userMessage->addContent(
                new ImageContent(
                    content: base64_encode(file_get_contents($image)),
                    sourceType: SourceType::BASE64,
                    mediaType: 'image/png'
                )
            );
        }

        try {
            $responseContent = $this->handler->chat($userMessage);
        } catch (Throwable $e) {
            report($e);

            // Log the failed turn so we can debug, but return a graceful
            // recovery message instead of bubbling 500 to the end user.
            $fallback = 'I ran into a hiccup processing that. Could you try rephrasing, '
                . 'or let me know if you want me to hand off to a human?';

            new KanvasConversationStore()->logTurn(
                userId: $this->user->getId(),
                sessionId: $sessionId,
                agentClass: get_class($this->handler),
                userMessage: $this->message,
                assistantResponse: $fallback,
                agentId: $this->agent->getId(),
                usage: ['error' => $e::class, 'message' => $e->getMessage()],
            );

            return $fallback;
        }

        $toolCalls = [];
        $toolResults = [];
        $usage = [];

        if ($responseContent instanceof AgentHandler) {
            $state = $responseContent->run();
            $responseMessage = $state->getMessage();
            [$toolCalls, $toolResults, $usage] = $this->extractTurnTelemetry($state, $responseMessage);
        } else {
            $responseMessage = $responseContent;
            if ($responseMessage instanceof Message && ($u = $responseMessage->getUsage())) {
                $usage = $u->jsonSerialize();
            }
        }

        $content = $responseMessage->getContent() ?? '';
        $response = ChatHelper::extractTextFromResponse($content);

        new KanvasConversationStore()->logTurn(
            userId: $this->user->getId(),
            sessionId: $sessionId,
            agentClass: get_class($this->handler),
            userMessage: $this->message,
            assistantResponse: $response,
            agentId: $this->agent->getId(),
            toolCalls: $toolCalls,
            toolResults: $toolResults,
            usage: $usage,
        );

        return $content;
    }

    /**
     * Walk this turn's intermediate steps (tool calls + their results) plus the
     * final assistant message, returning aggregated telemetry for persistence.
     *
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>, 2: array<string, int>}
     */
    private function extractTurnTelemetry(AgentState $state, Message $finalMessage): array
    {
        $toolCalls = [];
        $toolResults = [];
        $inputTokens = 0;
        $outputTokens = 0;
        $seenFinal = false;

        $accumulate = function (Message $m) use (&$toolCalls, &$toolResults, &$inputTokens, &$outputTokens): void {
            if ($m instanceof ToolCallMessage) {
                foreach ($m->getTools() as $tool) {
                    /** @var ToolInterface $tool */
                    $toolCalls[] = $tool->jsonSerialize();
                }
            }
            if ($m instanceof ToolResultMessage) {
                foreach ($m->getTools() as $tool) {
                    /** @var ToolInterface $tool */
                    $toolResults[] = $tool->jsonSerialize();
                }
            }
            if ($u = $m->getUsage()) {
                $inputTokens += $u->inputTokens;
                $outputTokens += $u->outputTokens;
            }
        };

        foreach ($state->getSteps() as $step) {
            if (! $step instanceof Message) {
                continue;
            }
            $accumulate($step);
            if ($step === $finalMessage) {
                $seenFinal = true;
            }
        }

        if (! $seenFinal) {
            $accumulate($finalMessage);
        }

        return [
            $toolCalls,
            $toolResults,
            ($inputTokens > 0 || $outputTokens > 0)
                ? ['input_tokens' => $inputTokens, 'output_tokens' => $outputTokens]
                : [],
        ];
    }
}
