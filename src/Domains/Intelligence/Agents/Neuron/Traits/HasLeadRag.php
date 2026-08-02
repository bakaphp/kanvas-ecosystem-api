<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Traits;

use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Knowledge\Retrieval\LeadKnowledgeRetrieval;
use Kanvas\Intelligence\Knowledge\Services\LeadRagComponents;
use NeuronAI\RAG\Embeddings\EmbeddingsProviderInterface;
use NeuronAI\RAG\Nodes\InstructionsNode;
use NeuronAI\RAG\Nodes\PostProcessNode;
use NeuronAI\RAG\Nodes\PreProcessNode;
use NeuronAI\RAG\Nodes\RetrievalNode;
use NeuronAI\RAG\Retrieval\RetrievalInterface;
use NeuronAI\RAG\VectorStore\MemoryVectorStore;
use NeuronAI\RAG\VectorStore\VectorStoreInterface;
use NeuronAI\Workflow\Node;
use Override;

trait HasLeadRag
{
    /**
     * @return list<Node>
     */
    protected function leadRagNodes(): array
    {
        return [
            new PreProcessNode($this->preProcessors()),
            new RetrievalNode($this->resolveRetrieval()),
            new PostProcessNode($this->postProcessors()),
            new InstructionsNode($this->resolveInstructions(), $this->bootstrapTools()),
        ];
    }

    #[Override]
    protected function retrieval(): RetrievalInterface
    {
        return new LeadKnowledgeRetrieval($this->resolveLeadForTurn());
    }

    #[Override]
    protected function embeddings(): EmbeddingsProviderInterface
    {
        if ($this->app === null) {
            throw new ValidationException(
                'App not set. Call setConfiguration() before resolving RAG embeddings.'
            );
        }

        return LeadRagComponents::embeddings($this->app);
    }

    #[Override]
    protected function vectorStore(): VectorStoreInterface
    {
        $lead = $this->resolveLeadForTurn();

        return $lead !== null
            ? LeadRagComponents::vectorStore($lead)
            : new MemoryVectorStore();
    }
}
