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
 * RAG wiring for a Neuron agent. Retrieval pulls the company knowledge base plus
 * the record in scope this turn (resolveEntityForTurn() — a Lead for SalesAgent,
 * any registered-source entity for a future agent).
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
        return new KnowledgeRetrieval(
            $this->app,
            $this->company,
            $this->resolveEntityForTurn()
        );
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

    // Retrieval is custom (retrieval() → KnowledgeRetrieval), so the RAG base never
    // queries this store; it only needs *a* VectorStoreInterface to satisfy the abstract.
    #[Override]
    protected function vectorStore(): VectorStoreInterface
    {
        return new MemoryVectorStore();
    }
}
