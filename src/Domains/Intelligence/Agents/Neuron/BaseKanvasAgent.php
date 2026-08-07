<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron;

use Kanvas\Intelligence\Agents\Neuron\Contracts\KanvasAgent;
use Kanvas\Intelligence\Agents\Neuron\Traits\HasKanvasAgentBehavior;
use NeuronAI\Agent\Agent;

class BaseKanvasAgent extends Agent implements KanvasAgent
{
    use HasKanvasAgentBehavior;
}
