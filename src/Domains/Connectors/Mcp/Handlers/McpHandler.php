<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Mcp\Handlers;

use Kanvas\Connectors\Contracts\BaseIntegration;
use Kanvas\Exceptions\ValidationException;
use Override;

/**
 * MCP servers are never connected for a whole company: every connection belongs to one agent, which
 * signs in with its own vendor account. The `integrations` rows still name this handler
 * because they remain the server catalog, so the generic integrations form lands here — and is pointed
 * at the per-agent flow instead of storing a company-wide credential.
 */
class McpHandler extends BaseIntegration
{
    #[Override]
    public function setup(): bool
    {
        throw new ValidationException(
            'MCP servers are connected per agent — use connectNervousSystemMcpServer with the agent that should hold it.'
        );
    }
}
