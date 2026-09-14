<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Types;

use Kanvas\Intelligence\Agents\Models\Agent;

/**
 * Base for agent types that execute on Claude Managed Agents.
 *
 * Unlike the Neuron bases, this is **not** an executor — `AgentChatKernel` short-circuits on
 * `isContainerRuntime()` and routes to `RunRuntimeChatAction` long before a handler is constructed,
 * so nothing ever calls a method on a subclass at chat time.
 *
 * It exists for two concrete reasons:
 * 1. `AgentTypeDiscoveryService::isCandidate()` only discovers classes extending a known handler
 *    base, so without one a `#[AgentTypeDefinition]` on a hosted agent would never sync.
 * 2. `instructions()` is snapshotted into `agent_types.instructions` at sync time, which is what
 *    lets a hosted agent's playbook live in a class next to its peers instead of in a DB column.
 */
class ClaudeManagedAgentHandler
{
    protected Agent $agent;

    public function setAgent(Agent $agent): void
    {
        $this->agent = $agent;
    }

    /**
     * Overridden by concrete types. Empty here so the discovery service records null rather than a
     * blank instructions column.
     */
    public function instructions(): string
    {
        return '';
    }
}
