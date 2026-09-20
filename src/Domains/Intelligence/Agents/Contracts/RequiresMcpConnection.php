<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Contracts;

/**
 * A native tool that only means anything while a given MCP server is connected — Kernel's file tools
 * reach into a browser session over that server's `exec_command`, and without it there is nothing to
 * read from.
 *
 * `MergesRegisteredTools` drops the tool from an agent that has no live connection to the named server,
 * rather than offering something that can only answer "not connected": a tool in the list is a promise
 * to the model, and one that always fails costs a round trip and pushes it toward a dead end.
 */
interface RequiresMcpConnection
{
    /**
     * The `integrations.name` of the server this tool works through (e.g. `kernel_mcp`).
     */
    public function requiredMcpServer(): string;
}
