<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Middleware;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\Events\AIInferenceEvent;
use NeuronAI\Chat\Enums\MessageRole;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Tools\Tool;
use NeuronAI\Workflow\Events\Event;
use NeuronAI\Workflow\Middleware\WorkflowMiddleware;
use NeuronAI\Workflow\NodeInterface;
use NeuronAI\Workflow\WorkflowState;
use Override;

/**
 * The history trimmer only cuts at a user message, so it can never shrink the turn in progress: a
 * tool loop that keeps pulling large payloads (a PR diff, whole files over MCP) grows one turn past
 * the provider's input limit and the request is rejected outright (KANVAS-ECOSYSTEM-606, Gemini's
 * 1,048,576-token ceiling). Bounding what each tool call adds is the only lever left inside the turn.
 *
 * Budgets are in characters, sized for the smallest context we run (200K tokens) with code and JSON
 * tokenizing at roughly 3 chars per token, leaving room for the system prompt and the trimmed history.
 */
class BoundToolResultsMiddleware implements WorkflowMiddleware
{
    public const int MAX_CHARS_PER_RESULT = 150_000;

    public const int MAX_CHARS_PER_TURN = 400_000;

    #[Override]
    public function before(NodeInterface $node, Event $event, WorkflowState $state): void
    {
    }

    #[Override]
    public function after(NodeInterface $node, Event $result, WorkflowState $state): void
    {
        if (! $result instanceof AIInferenceEvent || ! $state instanceof AgentState) {
            return;
        }

        $remaining = self::MAX_CHARS_PER_TURN - $this->charsSpentThisTurn($state);

        foreach ($result->getMessages() as $message) {
            if (! $message instanceof ToolResultMessage) {
                continue;
            }

            foreach ($message->getTools() as $tool) {
                if (! $tool instanceof Tool) {
                    continue;
                }

                $remaining -= $this->bound($tool, $remaining);
            }
        }
    }

    /**
     * The markers stay in the result on purpose — a model that can see the output was cut can narrow
     * its next call instead of answering from half a diff as though it were whole.
     */
    private function bound(Tool $tool, int $remaining): int
    {
        $output = $tool->getResult();
        $length = mb_strlen($output);

        if ($remaining <= 0) {
            $this->logCut($tool, $length, 0);

            $tool->setResult(
                "[Tool output omitted: this turn has already used its tool-output budget ({$length} characters withheld). "
                . 'Answer with what you have, or ask the person to narrow the request.]'
            );

            return 0;
        }

        $limit = min(self::MAX_CHARS_PER_RESULT, $remaining);

        if ($length <= $limit) {
            return $length;
        }

        $this->logCut($tool, $length, $limit);

        $tool->setResult(Str::limit(
            $output,
            $limit,
            "\n\n[... truncated: showing {$limit} of {$length} characters. Request a narrower slice (a single file, a page, a path) for the rest.]"
        ));

        return $limit;
    }

    /**
     * The limits are a guess at what a turn legitimately needs; this is how we find out whether real
     * agents run into them before deciding to move them.
     */
    private function logCut(Tool $tool, int $length, int $kept): void
    {
        Log::warning('Agent tool output bounded to protect the model context', [
            'tool' => $tool->getName(),
            'result_chars' => $length,
            'kept_chars' => $kept,
            'max_chars_per_result' => self::MAX_CHARS_PER_RESULT,
            'max_chars_per_turn' => self::MAX_CHARS_PER_TURN,
        ]);
    }

    private function charsSpentThisTurn(AgentState $state): int
    {
        $spent = 0;

        foreach (array_reverse($state->getChatHistory()->getMessages()) as $message) {
            if (! $message instanceof ToolResultMessage) {
                if ($message->getRole() === MessageRole::USER->value) {
                    break;
                }

                continue;
            }

            foreach ($message->getTools() as $tool) {
                $spent += mb_strlen($tool->getResult());
            }
        }

        return $spent;
    }
}
