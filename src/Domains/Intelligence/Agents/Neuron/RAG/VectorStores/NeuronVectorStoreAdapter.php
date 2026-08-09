<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\RAG\VectorStores;

use Kanvas\Intelligence\Knowledge\DataTransferObject\KnowledgeDocument;
use Kanvas\Intelligence\Knowledge\DataTransferObject\KnowledgeScope;
use Kanvas\Intelligence\Knowledge\VectorStores\TypesenseKnowledgeStore;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\VectorStore\VectorStoreInterface;
use Override;

/**
 * Bridges NeuronAI's VectorStoreInterface onto the shared TypesenseKnowledgeStore.
 * NeuronAI's interface is entity-less (similaritySearch takes only an embedding),
 * so the scope is bound here — the adapter is what pins a Neuron RAG agent's
 * reads to the current entity. Indexing itself goes through KnowledgeIndexer,
 * so addDocuments/deleteBy exist only to satisfy the interface.
 */
final class NeuronVectorStoreAdapter implements VectorStoreInterface
{
    public function __construct(
        private readonly TypesenseKnowledgeStore $store,
        private readonly KnowledgeScope $scope,
        private readonly int $topK = 8,
        private readonly ?float $minScore = null,
    ) {
    }

    #[Override]
    public function addDocument(Document $document): VectorStoreInterface
    {
        return $this->addDocuments([$document]);
    }

    #[Override]
    public function addDocuments(array $documents): VectorStoreInterface
    {
        $this->store->upsert(array_map(
            fn (Document $document): KnowledgeDocument => $this->toKnowledgeDocument($document),
            array_values($documents),
        ));

        return $this;
    }

    #[Override]
    public function deleteBySource(string $sourceType, string $sourceName): VectorStoreInterface
    {
        return $this->deleteBy($sourceType, $sourceName);
    }

    #[Override]
    public function deleteBy(string $sourceType, ?string $sourceName = null): VectorStoreInterface
    {
        if ($sourceName !== null) {
            $this->store->deleteBySource($this->scope, $sourceType, $sourceName);
        }

        return $this;
    }

    #[Override]
    public function similaritySearch(array $embedding): iterable
    {
        return array_map(
            fn (array $hit): Document => $this->toDocument($hit),
            $this->store->search($embedding, $this->scope, $this->topK, $this->minScore),
        );
    }

    private function toKnowledgeDocument(Document $document): KnowledgeDocument
    {
        return new KnowledgeDocument(
            id: (string) $document->getId(),
            content: $document->getContent(),
            metadata: [
                ...$document->metadata,
                'embedding' => $document->getEmbedding(),
                'sourceType' => $document->getSourceType(),
                'sourceName' => $document->getSourceName(),
            ],
        );
    }

    /** @param array{content: string, sourceType: string, sourceName: string, score: float, metadata: array<string, mixed>} $hit */
    private function toDocument(array $hit): Document
    {
        $document = new Document($hit['content']);
        $document->sourceType = $hit['sourceType'];
        $document->sourceName = $hit['sourceName'];
        $document->setScore($hit['score']);

        foreach (['source_type', 'source_id', 'channel_names', 'created_at'] as $field) {
            if (isset($hit['metadata'][$field])) {
                $document->addMetadata($field, $hit['metadata'][$field]);
            }
        }

        return $document;
    }
}
