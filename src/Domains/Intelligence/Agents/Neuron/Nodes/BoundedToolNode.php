<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Nodes;

use Kanvas\Intelligence\Agents\Neuron\Middleware\BoundToolResultsMiddleware;
use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\Nodes\ToolNode;
use NeuronAI\Chat\History\ChatHistory;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\Chat\Messages\ToolResultMessage;
use Override;

/**
 * ToolNode writes a round's results to the history as soon as the batch settles, and the store persists
 * them on that write, so this is the last moment an oversized result can be cut. Cutting any later leaves
 * the full payload in the thread, where it is replayed on every retry and on the next turn.
 */
class BoundedToolNode extends ToolNode
{
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
}
