<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron;

use Kanvas\Intelligence\Agents\Neuron\Contracts\KanvasAgent;
use Kanvas\Intelligence\Agents\Neuron\Traits\HasKanvasAgentBehavior;
use NeuronAI\RAG\RAG;
use NeuronAI\Workflow\Node;
use Override;

class BaseRagAgent extends RAG implements KanvasAgent
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
