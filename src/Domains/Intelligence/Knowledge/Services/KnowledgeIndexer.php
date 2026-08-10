<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Knowledge\Services;

use Illuminate\Database\Eloquent\Model;
use Kanvas\Intelligence\Knowledge\Contracts\KnowledgeEmbedder;
use Kanvas\Intelligence\Knowledge\Contracts\KnowledgeSource;
use Kanvas\Intelligence\Knowledge\DataTransferObject\KnowledgeDocument;
use Kanvas\Intelligence\Knowledge\DataTransferObject\KnowledgeEntity;
use Kanvas\Intelligence\Knowledge\DataTransferObject\KnowledgeScope;
use Kanvas\Intelligence\Knowledge\Support\KnowledgeChunker;
use Kanvas\Intelligence\Knowledge\VectorStores\TypesenseKnowledgeStore;

/**
 * The single framework-neutral indexer (chunk -> embed -> store). Serves two
 * entry points: indexEntity() builds a KnowledgeSource's documents for an entity
 * (a Lead + its messages), indexDocument() indexes raw content under any scope
 * (an Agent's own uploaded docs, or company-wide). Both replace-by-source so
 * re-indexing swaps a source's chunks in place instead of duplicating them.
 */
final class KnowledgeIndexer
{
    public function __construct(
        private readonly TypesenseKnowledgeStore $store,
        private readonly KnowledgeEmbedder $embedder,
        private readonly KnowledgeChunker $chunker = new KnowledgeChunker(),
    ) {
    }

    public function indexEntity(KnowledgeSource $source, Model $entity): int
    {
        $knowledgeEntity = KnowledgeEntity::fromModel($entity);

        $chunks = [];
        foreach ($source->build($entity) as $document) {
            foreach ($this->chunker->chunk($document->content) as $index => $piece) {
                $chunks[] = new KnowledgeDocument(
                    id: $document->id . '-' . $index,
                    content: $piece,
                    metadata: [
                        ...$document->metadata,
                        'sourceType' => $knowledgeEntity->type,
                        'sourceName' => $knowledgeEntity->key(),
                    ],
                );
            }
        }

        return $this->persistChunks(
            $chunks,
            KnowledgeScope::fromEntity($knowledgeEntity),
            $knowledgeEntity->type,
            $knowledgeEntity->key(),
        );
    }

    /**
     * Index raw content under a scope — company-wide (entity_id = 0) or scoped to
     * a specific entity (e.g. an Agent's own docs). entity_id is baked into the
     * chunk id so the same file attached to two agents doesn't collide.
     */
    public function indexDocument(
        KnowledgeScope $scope,
        string $sourceType,
        string $sourceName,
        string $content,
    ): int {
        $entityType = $scope->entityType ?? '';
        $entityId = $scope->entityId ?? 0;

        $chunks = [];
        foreach ($this->chunker->chunk($content) as $index => $piece) {
            $chunks[] = new KnowledgeDocument(
                id: "{$sourceType}:{$sourceName}:{$entityId}:{$index}",
                content: $piece,
                metadata: [
                    'apps_id' => $scope->appId,
                    'companies_id' => $scope->companyId,
                    'entity_type' => $entityType,
                    'entity_id' => $entityId,
                    'source_type' => $sourceType,
                    'source_id' => (string) $index,
                    'sourceType' => $sourceType,
                    'sourceName' => $sourceName,
                ],
            );
        }

        return $this->persistChunks($chunks, $scope, $sourceType, $sourceName);
    }

    /**
     * Embed every chunk in one batched call, then replace the source's prior
     * snapshot in place.
     *
     * @param array<int, KnowledgeDocument> $chunks
     */
    private function persistChunks(
        array $chunks,
        KnowledgeScope $scope,
        string $sourceType,
        string $sourceName,
    ): int {
        $this->store->replaceBySource($this->embed($chunks), $scope, $sourceType, $sourceName);

        return count($chunks);
    }

    /**
     * @param array<int, KnowledgeDocument> $chunks
     * @return array<int, KnowledgeDocument>
     */
    private function embed(array $chunks): array
    {
        if ($chunks === []) {
            return [];
        }

        $vectors = $this->embedder->embedBatch(
            array_map(static fn (KnowledgeDocument $chunk): string => $chunk->content, $chunks),
        );

        $embedded = [];
        foreach ($chunks as $index => $chunk) {
            $embedded[] = new KnowledgeDocument(
                id: $chunk->id,
                content: $chunk->content,
                metadata: [...$chunk->metadata, 'embedding' => $vectors[$index] ?? []],
            );
        }

        return $embedded;
    }
}
