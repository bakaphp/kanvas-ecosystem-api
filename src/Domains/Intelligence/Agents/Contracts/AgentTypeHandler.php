<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Contracts;

/**
 * Marks a class that `agent_types.handler` may name. The agent runtimes build the handler from that
 * column, so a class without this marker is never constructed from it.
 */
interface AgentTypeHandler
{
}
