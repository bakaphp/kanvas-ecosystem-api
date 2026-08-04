<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron;

use Kanvas\Intelligence\Agents\Neuron\Concerns\HasKanvasAgentBehavior;
use Kanvas\Intelligence\Agents\Neuron\Contracts\KanvasNeuronAgent;
use NeuronAI\RAG\RAG;
use NeuronAI\Workflow\Node;
use Override;

class NeuronRagAgent extends RAG implements KanvasNeuronAgent
{
    use HasKanvasAgentBehavior;

    /** @return list<Node> */
    #[Override]
    protected function ragNodes(): array
    {
        return method_exists($this, 'leadRagNodes')
            ? $this->leadRagNodes()
            : [];
    }
}
