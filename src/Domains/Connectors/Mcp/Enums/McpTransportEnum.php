<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Mcp\Enums;

/**
 * Remote transports only. `stdio` is deliberately absent: StdioTransport executes a configured command
 * with caller-supplied env, which is remote code execution on the API container. If stdio MCP is ever
 * needed it belongs inside the per-tenant OpenClaw/Hermes containers, not here.
 */
enum McpTransportEnum: string
{
    case HTTP = 'http';
    case SSE = 'sse';
}
