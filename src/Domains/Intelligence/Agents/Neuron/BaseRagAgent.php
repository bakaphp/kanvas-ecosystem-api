<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron;

use Kanvas\Intelligence\Agents\Neuron\Contracts\BehavesAsKanvasAgent;
use Kanvas\Intelligence\Agents\Neuron\Traits\HasKanvasAgentBehavior;
use Kanvas\Intelligence\Agents\Neuron\Traits\HasKnowledgeRag;
use NeuronAI\RAG\RAG;
use NeuronAI\Workflow\Node;
use Override;

class BaseRagAgent extends RAG implements BehavesAsKanvasAgent
{
    use HasKanvasAgentBehavior;
    use HasKnowledgeRag;

    /** @return list<Node> */
    #[Override]
    protected function ragNodes(): array
    {
        return method_exists($this, 'knowledgeRagNodes')
            ? $this->knowledgeRagNodes()
            : [];
    }

    protected function usesOrganizationWideKnowledge(): bool
    {
        return false;
    }
}
