<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Contracts;

/**
 * A native tool that works through an MCP server, and is worthless without it. `MergesRegisteredTools`
 * drops it from an agent with no live connection: a tool that can only answer "not connected" costs the
 * model a round trip and points it at a dead end.
 */
interface RequiresMcpConnection
{
    /** The `integrations.name` of the server this tool works through (e.g. `kernel_mcp`). */
    public function requiredMcpServer(): string;
}
