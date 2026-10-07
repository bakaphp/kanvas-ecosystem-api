<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron;

use Kanvas\Intelligence\Agents\Neuron\Concerns\HasKanvasAgentBehavior;
use Kanvas\Intelligence\Agents\Neuron\Concerns\HasKnowledgeRag;
use Kanvas\Intelligence\Agents\Neuron\Contracts\BehavesAsKanvasAgent;
use NeuronAI\RAG\RAG;

class BaseRagAgent extends RAG implements BehavesAsKanvasAgent
{
    use HasKanvasAgentBehavior;
    use HasKnowledgeRag;

    protected function usesOrganizationWideKnowledge(): bool
    {
        return false;
    }
}
