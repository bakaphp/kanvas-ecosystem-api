<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Nodes;

use Generator;
use Kanvas\Intelligence\Agents\Exceptions\UnknownToolRunsExceededException;
use Kanvas\Intelligence\Agents\Neuron\Middleware\BoundToolResultsMiddleware;
use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\Nodes\ToolNode;
use NeuronAI\Chat\History\ChatHistory;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolResultMessage;
use NeuronAI\Tools\ToolCall;
use NeuronAI\Tools\ToolRegistry;
use Override;

/**
 * ToolNode writes a round's results to the history as soon as the batch settles, and the store persists
 * them on that write, so this is the last moment an oversized result can be cut. Cutting any later leaves
 * the full payload in the thread, where it is replayed on every retry and on the next turn.
 */
class BoundedToolNode extends ToolNode
{
    public const int MAX_UNKNOWN_TOOL_ROUNDS = 3;

    /** @var array<string, true> */
    private array $unknownThisRound = [];

    /**
     * @param Message|array<int, Message> $messages
     */
    #[Override]
    protected function addToChatHistory(ChatHistory $history, AgentState $state, Message|array $messages): void
    {
        if ($messages instanceof ToolResultMessage) {
            BoundToolResultsMiddleware::bound($messages, $history, $state);
        }

        parent::addToChatHistory($history, $state, $messages);
    }

    /**
     * @param array<int, ToolCall> $calls
     */
    #[Override]
    protected function executeLocalTools(
        array $calls,
        string $messageId,
        AgentState $state,
        ToolRegistry $tools
    ): Generator {
        $this->unknownThisRound = [];

        return yield from parent::executeLocalTools($calls, $messageId, $state, $tools);
    }

    /**
     * A call to a tool the agent does not hold throws on the registry lookup, before the engine counts
     * it, so nothing stops a model that keeps naming it (KANVAS-ECOSYSTEM-70A). Counted once per round:
     * several calls in one response were all made before the model was told the tool does not exist.
     */
    #[Override]
    protected function checkToolRuns(ToolCall $call, AgentState $state, ToolRegistry $tools): void
    {
        $name = $call->getName();

        if ($tools->find($name) === null && ! isset($this->unknownThisRound[$name])) {
            $this->unknownThisRound[$name] = true;
            $state->incrementToolRun('unknown:' . $name);

            if ($state->getToolRuns('unknown:' . $name) > self::MAX_UNKNOWN_TOOL_ROUNDS) {
                throw new UnknownToolRunsExceededException(sprintf(
                    'Tool %s is not registered on this agent and was called again after %d refusals',
                    $name,
                    self::MAX_UNKNOWN_TOOL_ROUNDS
                ));
            }
        }

        parent::checkToolRuns($call, $state, $tools);
    }
}
