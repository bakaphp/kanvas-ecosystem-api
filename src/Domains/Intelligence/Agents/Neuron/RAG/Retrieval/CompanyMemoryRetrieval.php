<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\RAG\Retrieval;

use Illuminate\Support\Facades\Log;
use Kanvas\Intelligence\Agents\Neuron\Memory\ConversationMemoryNode;
use Kanvas\Intelligence\Knowledge\DataTransferObject\KnowledgeScope;
use Kanvas\Intelligence\Knowledge\Sources\LedgerKnowledgeSource;
use NeuronAI\Chat\Messages\Message;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\Embeddings\EmbeddingsProviderInterface;
use NeuronAI\RAG\Retrieval\SimilarityRetrieval;
use NeuronAI\RAG\VectorStore\Filter\Filter;
use NeuronAI\RAG\VectorStore\Filter\FilterExpression;
use NeuronAI\RAG\VectorStore\Filter\FilterGroup;
use NeuronAI\RAG\VectorStore\VectorStoreInterface;
use Override;
use Throwable;

/**
 * What any of the company's agents learned, agreed or did: the memory document kinds, pinned to one
 * tenant pair here as well as by the agent's retrieval scope, so neither alone can be widened into
 * another company. The recall scope narrows it by audience: a customer-facing agent to its prospect, an
 * internal one to its own human's conversations plus what the company kept on purpose. Each
 * hit is labelled with its kind and date so the model knows the provenance and the age of what it is
 * reading.
 */
final class CompanyMemoryRetrieval extends SimilarityRetrieval
{
    /** The memory document kinds, each with the label the model reads it under. */
    private const array LABELS = [
        ConversationMemoryNode::SOURCE_TYPE => 'Earlier conversation',
        LedgerKnowledgeSource::MEMORY_SOURCE_TYPE => 'Saved memory',
        LedgerKnowledgeSource::OUTCOME_SOURCE_TYPE => 'Ledger',
    ];

    public function __construct(
        VectorStoreInterface $store,
        EmbeddingsProviderInterface $embeddings,
        int $appId,
        int $companyId,
        ?FilterExpression $recallScope = null,
        ?string $inContextThreadId = null,
        ?int $inContextSince = null,
    ) {
        $scope = [self::scopeFor($appId, $companyId, $recallScope)];

        // The turns still in the model's window are already in the prompt; recalling them again costs
        // tokens and repeats them. Older turns of the same thread, archived by a trim or a summary, are
        // exactly what memory is for, so the cut is by time inside the thread, not by thread.
        if ($inContextThreadId !== null && $inContextSince !== null) {
            $scope[] = FilterGroup::or(
                Filter::neq('sourceName', $inContextThreadId),
                Filter::lt('created_at', $inContextSince),
            );
        }

        parent::__construct($store, $embeddings, FilterGroup::and(...$scope));
    }

    /**
     * The memory kinds of one tenant, narrowed by audience: what every read of company memory pins,
     * whether the automatic recall or the search_knowledge tool.
     */
    public static function scopeFor(int $appId, int $companyId, ?FilterExpression $recallScope): FilterExpression
    {
        $scope = [
            Filter::eq('apps_id', $appId),
            Filter::eq('companies_id', $companyId),
            Filter::in('source_type', KnowledgeScope::MEMORY_SOURCE_TYPES),
        ];

        if ($recallScope !== null) {
            $scope[] = $recallScope;
        }

        return FilterGroup::and(...$scope);
    }

    /**
     * @return list<Document>
     */
    #[Override]
    public function retrieve(Message $query, ?FilterExpression $filters = null): array
    {
        if (trim((string) $query->getContent()) === '') {
            return [];
        }

        // Memory is on for every tenant, so a Typesense or embedding outage must cost the answer its
        // recall, never the answer itself.
        try {
            $documents = parent::retrieve($query, $filters);
        } catch (Throwable $e) {
            Log::warning('Company memory recall failed; answering without it', [
                'exception' => $e::class,
                'error' => $e->getMessage(),
            ]);

            return [];
        }

        return array_map(self::label(...), $documents);
    }

    public static function label(Document $document): Document
    {
        $metadata = $document->getMetadata();
        $kind = (string) ($metadata['source_type'] ?? $document->getSourceType());
        $label = self::LABELS[$kind] ?? $kind;
        $createdAt = (int) ($metadata['created_at'] ?? 0);
        $when = $createdAt > 0 ? ', ' . date('Y-m-d', $createdAt) : '';

        $labelledDocument = new Document("[{$label}{$when}] " . $document->getContent())
            ->setId($document->getId())
            ->setSourceType($document->getSourceType())
            ->setSourceName($document->getSourceName())
            ->setScore($document->getScore())
            ->setMetadata($metadata);

        if ($document->getEmbedding() !== null) {
            $labelledDocument->setEmbedding($document->getEmbedding());
        }

        return $labelledDocument;
    }
}
