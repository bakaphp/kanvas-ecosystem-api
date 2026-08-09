<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Traits;

use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Neuron\RAG\Retrieval\KnowledgeRetrieval;
use Kanvas\Intelligence\Agents\Neuron\RAG\Services\RagComponents;
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

/**
 * Entity-scoped RAG wiring for a Neuron agent. Entity-agnostic — it retrieves
 * knowledge for whatever record is in scope this turn (resolveEntityForTurn():
 * a Lead for SalesAgent; a Deal/Order/etc. for a future agent, once that entity
 * has a registered KnowledgeSource). Retrieval, embeddings and the vector store
 * all resolve from that one entity, so a customer-facing agent's memory stays
 * scoped to the record in scope.
 */
trait HasKnowledgeRag
{
    /**
     * @return list<Node>
     */
    protected function knowledgeRagNodes(): array
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
        return new KnowledgeRetrieval($this->resolveEntityForTurn());
    }

    #[Override]
    protected function embeddings(): EmbeddingsProviderInterface
    {
        if ($this->app === null) {
            throw new ValidationException(
                'App not set. Call setConfiguration() before resolving RAG embeddings.'
            );
        }

        return RagComponents::embeddings($this->app);
    }

    #[Override]
    protected function vectorStore(): VectorStoreInterface
    {
        $entity = $this->resolveEntityForTurn();

        return $entity !== null
            ? RagComponents::vectorStore($entity)
            : new MemoryVectorStore();
    }
}
