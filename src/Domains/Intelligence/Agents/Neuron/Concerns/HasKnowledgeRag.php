<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Concerns;

use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Neuron\RAG\Retrieval\CompanyMemoryRetrieval;
use Kanvas\Intelligence\Agents\Neuron\RAG\Retrieval\KnowledgeRetrieval;
use Kanvas\Intelligence\Agents\Neuron\RAG\Services\RagComponents;
use Kanvas\Intelligence\Knowledge\Services\KnowledgeComponents;
use NeuronAI\RAG\Embeddings\EmbeddingsProviderInterface;
use NeuronAI\RAG\PostProcessor\AdaptiveThresholdPostProcessor;
use NeuronAI\RAG\PreProcessor\QueryTransformationPreProcessor;
use NeuronAI\RAG\PreProcessor\QueryTransformationType;
use NeuronAI\RAG\Retrieval\CompositeRetrieval;
use NeuronAI\RAG\Retrieval\RetrievalInterface;
use NeuronAI\RAG\VectorStore\Filter\Filter;
use NeuronAI\RAG\VectorStore\Filter\FilterExpression;
use NeuronAI\RAG\VectorStore\Filter\FilterGroup;
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
        $knowledge = new KnowledgeRetrieval(
            $this->app,
            $this->company,
            $this->agent,
            $this->resolveEntityForTurn(),
            organizationWide: $this->usesOrganizationWideKnowledge(),
        );

        if (! $this->companyMemoryActive()) {
            return $knowledge;
        }

        $recallScope = $this->recallMemoryScope();

        if ($recallScope === null) {
            return $knowledge;
        }

        return new CompositeRetrieval([
            $knowledge,
            new CompanyMemoryRetrieval(
                store: $this->companyMemoryStore(),
                embeddings: $this->companyMemoryEmbeddings(),
                appId: $this->app->getId(),
                companyId: $this->company->getId(),
                topK: KnowledgeComponents::memoryResultLimit($this->app),
                recallScope: $recallScope,
            ),
        ]);
    }

    /**
     * The tenant pair, AND-ed by Neuron onto every retrieval of this agent. KnowledgeRetrieval pins it
     * through KnowledgeScope on its own; this is what pins the memory retrieval and anything added later.
     */
    #[Override]
    protected function retrievalScope(): ?FilterExpression
    {
        if ($this->app === null || $this->company === null) {
            return null;
        }

        return FilterGroup::and(
            Filter::eq('apps_id', $this->app->getId()),
            Filter::eq('companies_id', $this->company->getId()),
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
