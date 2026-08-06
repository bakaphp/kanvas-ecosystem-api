<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron;

use Kanvas\Intelligence\Agents\Neuron\Traits\HasKanvasAgentBehavior;
use Kanvas\Intelligence\Agents\Neuron\Contracts\KanvasNeuronAgent;
use NeuronAI\Agent\Agent;

class BaseKanvasAgent extends Agent implements KanvasNeuronAgent
{
    use HasKanvasAgentBehavior;
}
