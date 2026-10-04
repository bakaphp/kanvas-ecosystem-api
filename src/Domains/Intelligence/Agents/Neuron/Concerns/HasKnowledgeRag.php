<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Concerns;

use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Neuron\RAG\Retrieval\KnowledgeRetrieval;
use Kanvas\Intelligence\Agents\Neuron\RAG\Services\RagComponents;
use NeuronAI\RAG\Embeddings\EmbeddingsProviderInterface;
use NeuronAI\RAG\PostProcessor\AdaptiveThresholdPostProcessor;
use NeuronAI\RAG\PreProcessor\QueryTransformationPreProcessor;
use NeuronAI\RAG\PreProcessor\QueryTransformationType;
use NeuronAI\RAG\Retrieval\RetrievalInterface;
use NeuronAI\RAG\VectorStore\MemoryVectorStore;
use NeuronAI\RAG\VectorStore\VectorStoreInterface;
use Override;

/**
 * RAG wiring for a Neuron agent. Retrieval pulls the agent's own uploaded docs
 * plus the record in scope this turn (resolveEntityForTurn() — a Lead for
 * SalesAgent, any registered-source entity for a future agent). Scoped per agent
 * so knowledge never leaks between agents. The retrieval chain itself (pre-process,
 * retrieve, post-process, inject) is Neuron's stock RAG entry chain built from these hooks.
 */
trait HasKnowledgeRag
{
    #[Override]
    protected function retrieval(): RetrievalInterface
    {
        return new KnowledgeRetrieval(
            $this->app,
            $this->company,
            $this->agent,
            $this->resolveEntityForTurn(),
            organizationWide: $this->usesOrganizationWideKnowledge(),
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

    #[Override]
    protected function preProcessors(): array
    {
        return [
            new QueryTransformationPreProcessor(
                provider: $this->getProvider(),
                transformation: QueryTransformationType::REWRITING,
            ),
        ];
    }

    #[Override]
    protected function postProcessors(): array
    {
        return [
            new AdaptiveThresholdPostProcessor(multiplier: 0.6),
        ];
    }
}
