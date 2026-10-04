<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Concerns;

use Kanvas\Intelligence\Agents\Contracts\ConversesWithCustomer;
use Kanvas\Intelligence\Agents\Neuron\RAG\Retrieval\CompanyMemoryRetrieval;
use Kanvas\Intelligence\Agents\Neuron\RAG\Retrieval\KnowledgeRetrieval;
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
 * RAG wiring for a Neuron agent: the agent's knowledge (KnowledgeRetrieval, scoped to the agent and
 * the record in scope this turn) plus, when the agent remembers for the company, company memory
 * narrowed by recallMemoryScope(). The chain itself (pre-process, retrieve, post-process, inject) is
 * Neuron's stock RAG entry chain built from these hooks.
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
        return $this->companyMemoryEmbeddings();
    }

    // Retrieval is custom (retrieval() → KnowledgeRetrieval), so the RAG base never
    // queries this store; it only needs *a* VectorStoreInterface to satisfy the abstract.
    #[Override]
    protected function vectorStore(): VectorStoreInterface
    {
        return new MemoryVectorStore();
    }

    /**
     * The rewrite is one more LLM call before every retrieval, 3-4 s on a thinking model, and it only
     * sees the one message, so it cannot resolve a follow-up. It earns that on a prospect's terse
     * "price?" and not on a teammate's explicit question, so only customer-facing agents keep it.
     */
    #[Override]
    protected function preProcessors(): array
    {
        if (! $this instanceof ConversesWithCustomer) {
            return [];
        }

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
