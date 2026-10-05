<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Middleware;

use Kanvas\Intelligence\Agents\Exceptions\AgentTurnCancelledException;
use Kanvas\Intelligence\Agents\Services\AgentTurnCancellationService;
use NeuronAI\Agent\AgentResources;
use NeuronAI\Agent\AgentState;
use NeuronAI\Agent\Events\AIInferenceEvent;
use NeuronAI\Agent\Events\ToolCallEvent;
use NeuronAI\Agent\Middleware\AgentMiddleware;
use NeuronAI\Agent\Nodes\AgentNodeInterface;
use NeuronAI\Workflow\Events\Event;
use Override;

/**
 * Stops a turn at the next boundary a person can safely stop it at: before an inference round and
 * before a tool call. A request already at the provider finishes, and a tool that started runs to its
 * end, so a stop never leaves a write half done. Registered on ChatNode and ToolNode.
 */
final class CancelsOnRequestMiddleware extends AgentMiddleware
{
    #[Override]
    protected function beforeAgentNode(
        AgentNodeInterface $node,
        Event $event,
        AgentState $state,
        AgentResources $resources
    ): void {
        if (! $event instanceof AIInferenceEvent && ! $event instanceof ToolCallEvent) {
            return;
        }

        $threadId = $resources->history->getThreadId();

        if (AgentTurnCancellationService::isRequested($threadId)) {
            throw new AgentTurnCancelledException($threadId);
        }
    }
}
