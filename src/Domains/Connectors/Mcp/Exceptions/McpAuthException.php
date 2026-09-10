<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Mcp\Exceptions;

use NeuronAI\MCP\McpException;

/**
 * The server rejected our credential (401/403).
 *
 * Distinct from McpFetchException on purpose: the capability is genuinely gone, not temporarily
 * unreachable, so the resolver returns no tools and flips the integration to FAILED rather than
 * serving a stale snapshot the agent could not actually use.
 */
class McpAuthException extends McpException
{
}
