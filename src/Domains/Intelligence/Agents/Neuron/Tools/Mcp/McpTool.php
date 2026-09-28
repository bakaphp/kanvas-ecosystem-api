<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Mcp;

use NeuronAI\Tools\HasRunKey;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\TrackByInputs;

class McpTool extends Tool implements HasRunKey
{
    use TrackByInputs;
}
