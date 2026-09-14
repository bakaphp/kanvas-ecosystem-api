<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Mcp\Enums;

/**
 * An agent's connection to one MCP server, kept on its grant row (`nervous_system_agent_tools.config`).
 * FAILED is set when the vendor rejects the agent's credential; only a reconnect clears it.
 */
enum McpConnectionStatusEnum: string
{
    case ACTIVE = 'active';
    case FAILED = 'failed';
}
