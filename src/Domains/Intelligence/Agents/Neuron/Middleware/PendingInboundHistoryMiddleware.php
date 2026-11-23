<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Middleware;

use Kanvas\Intelligence\Agents\ChatHistory\KanvasChatHistory;
use NeuronAI\Agent\AgentResources;
use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\Events\AIInferenceEvent;
use NeuronAI\Agent\Events\ToolCallEvent;
use NeuronAI\Agent\Middleware\AgentMiddleware;
use NeuronAI\Agent\Nodes\AgentNodeInterface;
use NeuronAI\Workflow\Events\Event;
use Override;

final class PendingInboundHistoryMiddleware extends AgentMiddleware
{
    private bool $started = false;

    #[Override]
    protected function beforeAgentNode(
        AgentNodeInterface $node,
        Event $event,
        AgentState $state,
        AgentResources $resources
    ): void {
        if ($this->started || (! $event instanceof AIInferenceEvent && ! $event instanceof ToolCallEvent)) {
            return;
        }

        $this->started = true;

        // Starting at a tool, or at inference with no pending messages, means the previous worker
        // already sent the prompt. Later tool rounds of a fresh turn keep their in-memory prompt.
        if (
            $resources->history instanceof KanvasChatHistory
            && ($event instanceof ToolCallEvent || $state->request->messages === [])
        ) {
            $resources->history->restoreConsumedSocialMessages();
        }
    }
}
