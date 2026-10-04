<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Middleware;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Kanvas\Intelligence\Agents\Neuron\Tools\Fallback\RefusedToolStub;
use NeuronAI\Agent\AgentResources;
use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\Events\AIInferenceEvent;
use NeuronAI\Agent\Events\ToolCallEvent;
use NeuronAI\Agent\Middleware\AgentMiddleware;
use NeuronAI\Agent\Nodes\AgentNodeInterface;
use NeuronAI\Chat\History\ChatHistory;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Workflow\Events\Event;
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
 *
 * A call is refused by swapping its live tool for a RefusedToolStub in the segment's registry: ToolNode
 * resolves every call against that registry, and the registry is rebuilt for the next segment, so the
 * swap lasts exactly one turn. Stamping the call rejected would record a human decision that nobody made.
 */
class BoundToolResultsMiddleware extends AgentMiddleware
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
    protected function beforeAgentNode(
        AgentNodeInterface $node,
        Event $event,
        AgentState $state,
        AgentResources $resources
    ): void {
        if (! $event instanceof ToolCallEvent) {
            return;
        }

        if (self::MAX_CHARS_PER_TURN - $this->charsSpentThisTurn($resources->history) > 0) {
            return;
        }

        foreach ($event->toolCallMessage->getToolCalls() as $call) {
            $resources->tools->remove($call->getName());
            $resources->tools->add(new RefusedToolStub($call->getName(), self::NOT_EXECUTED));
        }

        $state->set(self::EXHAUSTED, true);
    }

    /**
     * ToolNode hands the round's call and results to the next inference through the request, not the
     * history: they are committed only after that provider call succeeds. So the results to bound are
     * on the request's trailing ToolResultMessage, and what the history already holds for this turn is
     * spent.
     */
    #[Override]
    protected function afterAgentNode(
        AgentNodeInterface $node,
        Event $result,
        AgentState $state,
        AgentResources $resources
    ): void {
        if (! $result instanceof AIInferenceEvent) {
            return;
        }

        $inbound = $state->request->messages;
        $last = end($inbound);

        if (! $last instanceof ToolResultMessage) {
            return;
        }

        $remaining = self::MAX_CHARS_PER_TURN - $this->charsSpentThisTurn($resources->history);

        foreach ($last->getToolCalls() as $call) {
            if (self::length($call) === 0 || $this->isRefused($call)) {
                continue;
            }

            $remaining -= $this->bound($call, $remaining, $state);
        }
    }

    /**
     * Whether this turn ran out of tool-output budget rather than finishing on its own.
     */
    public static function exhausted(AgentState $state): bool
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

        foreach ($state->getSteps() as $message) {
            if (! $message instanceof ToolResultMessage) {
                continue;
            }

            foreach ($message->getToolCalls() as $call) {
                if ($call->hasResult() && (string) $call->getResult() !== self::NOT_EXECUTED) {
                    $calls[] = $call->getName() . ':' . sha1((string) json_encode($call->getInputs()));
                }
            }
        }

        return array_values(array_unique($calls));
    }

    /**
     * The markers stay in the result on purpose — a model that can see the output was cut can narrow
     * its next call instead of answering from half a diff as though it were whole.
     */
    private function bound(ToolCall $call, int $remaining, AgentState $state): int
    {
        $output = (string) $call->getResult();
        $length = mb_strlen($output);

        if ($remaining <= 0) {
            $this->logCut($call, $length, 0);
            $state->set(self::EXHAUSTED, true);

            // Calls in the same batch as the one that spent the budget have already run, so this one
            // must not read as refused: it happened, its outcome is simply unseen.
            $call->setResult(
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

        $this->logCut($call, $length, $limit);

        $call->setResult(Str::limit(
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
    private function logCut(ToolCall $call, int $length, int $kept): void
    {
        Log::warning('Agent tool output bounded to protect the model context', [
            'tool' => $call->getName(),
            'result_chars' => $length,
            'kept_chars' => $kept,
            'max_chars_per_result' => self::MAX_CHARS_PER_RESULT,
            'max_chars_per_turn' => self::MAX_CHARS_PER_TURN,
        ]);
    }

    private function charsSpentThisTurn(ChatHistory $history): int
    {
        $spent = 0;

        foreach (self::toolResultsThisTurn($history) as $call) {
            $spent += self::length($call);
        }

        return $spent;
    }

    private function isRefused(ToolCall $call): bool
    {
        return (string) $call->getResult() === self::NOT_EXECUTED;
    }

    private static function length(ToolCall $call): int
    {
        return $call->hasResult() ? mb_strlen((string) $call->getResult()) : 0;
    }

    /**
     * The turn starts at the last message a person sent; tool results carry the user role on some
     * providers, so they are recognised by type before the role check can end the walk.
     *
     * @return list<ToolCall>
     */
    private static function toolResultsThisTurn(ChatHistory $history): array
    {
        $calls = [];

        foreach (array_reverse($history->getMessages()) as $message) {
            if (! $message instanceof ToolResultMessage) {
                if ($message::class === UserMessage::class) {
                    break;
                }

                continue;
            }

            foreach ($message->getToolCalls() as $call) {
                $calls[] = $call;
            }
        }

        return $calls;
    }
}
