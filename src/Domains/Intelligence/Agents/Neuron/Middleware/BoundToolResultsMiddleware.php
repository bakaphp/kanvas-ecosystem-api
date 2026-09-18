<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Middleware;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\Events\AIInferenceEvent;
use NeuronAI\Agent\Events\ToolCallEvent;
use NeuronAI\Agent\Tools\ToolRejectionHandler;
use NeuronAI\Chat\Enums\MessageRole;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolInterface;
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
 *
 * Once the turn's budget is spent a call is refused rather than run with its output hidden. A hidden
 * result leaves the model unable to tell whether a write happened, so it reports the item as pending
 * and the next turn creates it a second time. A refused call changed nothing, so saying so is true.
 */
class BoundToolResultsMiddleware implements WorkflowMiddleware
{
    public const int MAX_CHARS_PER_RESULT = 150_000;

    public const int MAX_CHARS_PER_TURN = 400_000;

    /**
     * The model's report is the only thing the next turn sees of this one — its raw tool results are
     * never replayed — so both notes ask for the same hand-off: what is done and what is left, by id.
     */
    public const string NOT_EXECUTED = '[Not executed: this turn has used up its tool-output budget, so this call was '
        . 'not run and nothing changed. Stop calling tools. Report exactly what is done and what is still left, '
        . 'with the ids involved, so the work can resume from your report.]';

    private const string EXHAUSTED = 'tool_output_budget_exhausted';

    #[Override]
    public function before(NodeInterface $node, Event $event, WorkflowState $state): void
    {
        if (! $event instanceof ToolCallEvent || ! $state instanceof AgentState) {
            return;
        }

        if (self::MAX_CHARS_PER_TURN - $this->charsSpentThisTurn($state) > 0) {
            return;
        }

        foreach ($event->toolCallMessage->getTools() as $tool) {
            if ($tool instanceof Tool) {
                $tool->setCallable(new ToolRejectionHandler(self::NOT_EXECUTED));
            }
        }

        $state->set(self::EXHAUSTED, true);
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
                if (! $tool instanceof Tool || $tool->getResult() === self::NOT_EXECUTED) {
                    continue;
                }

                $remaining -= $this->bound($tool, $remaining, $state);
            }
        }
    }

    /**
     * Whether this turn ran out of tool-output budget rather than finishing on its own.
     */
    public static function exhausted(WorkflowState $state): bool
    {
        return (bool) $state->get(self::EXHAUSTED, false);
    }

    /**
     * Every call that actually ran this turn, as `name:sha1(inputs)`. Refused calls are left out, so a
     * later turn that repeats only what already ran has made no progress.
     *
     * @return list<string>
     */
    public static function executedCalls(AgentState $state): array
    {
        $calls = [];

        foreach (self::toolResultsThisTurn($state) as $tool) {
            if ($tool->getResult() !== self::NOT_EXECUTED) {
                $calls[] = $tool->getName() . ':' . sha1((string) json_encode($tool->getInputs()));
            }
        }

        return array_values(array_unique($calls));
    }

    /**
     * The markers stay in the result on purpose — a model that can see the output was cut can narrow
     * its next call instead of answering from half a diff as though it were whole.
     */
    private function bound(Tool $tool, int $remaining, AgentState $state): int
    {
        $output = $tool->getResult();
        $length = mb_strlen($output);

        if ($remaining <= 0) {
            $this->logCut($tool, $length, 0);
            $state->set(self::EXHAUSTED, true);

            // Calls in the same batch as the one that spent the budget have already run, so this one
            // must not read as refused: it happened, its outcome is simply unseen.
            $tool->setResult(
                "[This call ran, but its output was withheld: this turn has used up its tool-output budget ({$length} "
                . 'characters). Treat it as done but unconfirmed, and check its result before repeating it. Stop '
                . 'calling tools. Report exactly what is done and what is still left, with the ids involved.]'
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

        foreach (self::toolResultsThisTurn($state) as $tool) {
            $spent += mb_strlen($tool->getResult());
        }

        return $spent;
    }

    /**
     * The turn starts at the last message a person sent; tool results can carry the user role on some
     * providers, so they are recognised by type before the role check can end the walk.
     *
     * @return list<ToolInterface>
     */
    private static function toolResultsThisTurn(AgentState $state): array
    {
        $tools = [];

        foreach (array_reverse($state->getChatHistory()->getMessages()) as $message) {
            if (! $message instanceof ToolResultMessage) {
                if ($message->getRole() === MessageRole::USER->value) {
                    break;
                }

                continue;
            }

            foreach ($message->getTools() as $tool) {
                $tools[] = $tool;
            }
        }

        return $tools;
    }
}
