<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Mcp\Exceptions;

use NeuronAI\MCP\McpException;

/**
 * The server could not be reached or answered badly — timeout, 5xx, unparseable body, oversized body.
 *
 * Recoverable by assumption: the tools almost certainly still exist, so the resolver serves the last
 * good snapshot instead of stripping the agent's capability for the duration of someone else's outage.
 */
class McpFetchException extends McpException
{
}
