<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Mcp;

use NeuronAI\MCP\McpTool as NeuronMcpTool;
use NeuronAI\Tools\TrackByInputs;

/**
 * Neuron's MCP tool keyed by inputs, so a loop over distinct records keeps its own run budget per call
 * while an identical repeat is still capped.
 */
class McpTool extends NeuronMcpTool
{
    use TrackByInputs;
}
