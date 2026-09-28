<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Knowledge\VectorStores;

use Kanvas\Intelligence\Knowledge\DataTransferObject\KnowledgeDocument;
use Kanvas\Intelligence\Knowledge\DataTransferObject\KnowledgeScope;
use RuntimeException;
use Typesense\Client;
use Typesense\Exceptions\ObjectNotFound;

/**
 * The one Typesense-backed knowledge store. Neutral (no NeuronAI, no laravel/ai)
 * — it speaks KnowledgeDocument + KnowledgeScope so both the entity-scoped Lead
 * RAG path and the tenant-scoped company-docs path share one collection shape.
 * Every read/delete is scoped through KnowledgeScope::filter(), which always
 * pins app + company (closing the cross-tenant leak). External agents add an
 * entity boundary, tenant-document reads pin entity_id = 0, and explicitly
 * internal organization reads may search every entity inside that company.
 */
final class TypesenseKnowledgeStore
{
    public function __construct(
        private readonly Client $client,
        private readonly string $collection,
        private readonly int $vectorDimension,
    ) {
    }

    /**
     * Each document must carry its float[] vector in metadata['embedding'].
     *
     * @param array<int, KnowledgeDocument> $documents
     */
    public function upsert(array $documents): void
    {
        if ($documents === []) {
            return;
        }

        $this->ensureCollection();
        $records = array_map(fn (KnowledgeDocument $document): array => $this->toRecord($document), $documents);
        $results = $this->client->collections[$this->collection]->documents->import(
            $records,
            ['action' => 'upsert'],
        );

        foreach ($results as $result) {
            if (($result['success'] ?? false) !== true) {
                throw new RuntimeException(
                    'Typesense rejected a knowledge document: ' . ($result['error'] ?? 'unknown error'),
                );
            }
        }
    }

    /**
     * Import the new snapshot before pruning stale rows: a failed embed/import
     * leaves the previous snapshot queryable instead of creating a knowledge gap.
     *
     * @param array<int, KnowledgeDocument> $documents
     */
    public function replaceBySource(
        array $documents,
        KnowledgeScope $scope,
        string $sourceType,
        string $sourceName,
    ): void {
        $existingIds = $this->documentIds($scope, $sourceType, $sourceName);
        $this->upsert($documents);

        $currentIds = array_fill_keys(
            array_map(static fn (KnowledgeDocument $document): string => $document->id, $documents),
            true,
        );

        foreach ($existingIds as $id) {
            if (! isset($currentIds[$id])) {
                $this->client->collections[$this->collection]->documents[$id]->delete();
            }
        }
    }

    public function deleteBySource(KnowledgeScope $scope, string $sourceType, string $sourceName): void
    {
        if (! $this->collectionExists()) {
            return;
        }

        $this->client->collections[$this->collection]->documents->delete([
            'filter_by' => $this->sourceFilter($scope, $sourceType, $sourceName),
        ]);
    }

    /**
     * Delete a source's chunks under a company regardless of entity scope — used
     * when a file is deleted and we don't know which agent(s) it was scoped to.
     */
    public function deleteBySourceAcrossEntities(
        int $appId,
        int $companyId,
        string $sourceType,
        string $sourceName
    ): void {
        if (! $this->collectionExists()) {
            return;
        }

        $filter = sprintf('apps_id:=%d && companies_id:=%d', $appId, $companyId)
            . ' && sourceType:=' . KnowledgeScope::escapeFilterValue($sourceType)
            . ' && sourceName:=' . KnowledgeScope::escapeFilterValue($sourceName);

        $this->client->collections[$this->collection]->documents->delete(['filter_by' => $filter]);
    }

    /**
     * @param array<int, float> $embedding
     * @param float|null $minScore drop hits below this similarity (score = 1 - distance); null keeps all
     * @return array<int, array{content: string, sourceType: string, sourceName: string, score: float, metadata: array<string, mixed>}>
     */
    public function search(
        array $embedding,
        KnowledgeScope $scope,
        int $topK = 8,
        ?float $minScore = null
    ): array {
        if (! $this->collectionExists()) {
            return [];
        }

        $response = $this->client->multiSearch->perform([
            'searches' => [[
                'collection' => $this->collection,
                'q' => '*',
                'vector_query' => 'embedding:(' . (string) json_encode($embedding) . ', k:' . $topK . ')',
                'filter_by' => $scope->filter(),
                'exclude_fields' => 'embedding',
                'per_page' => $topK,
                'num_candidates' => max(50, $topK * 4),
            ]],
        ]);

        $hits = array_map(static function (array $hit): array {
            $document = $hit['document'];

            return [
                'content' => (string) ($document['content'] ?? ''),
                'sourceType' => (string) ($document['sourceType'] ?? ''),
                'sourceName' => (string) ($document['sourceName'] ?? ''),
                'score' => 1.0 - (float) ($hit['vector_distance'] ?? 1.0),
                'metadata' => $document,
            ];
        }, $response['results'][0]['hits'] ?? []);

        if ($minScore === null) {
            return $hits;
        }

        return array_values(array_filter($hits, static fn (array $hit): bool => $hit['score'] >= $minScore));
    }

    private function toRecord(KnowledgeDocument $document): array
    {
        $metadata = $document->metadata;

        return [
            'id' => $document->id,
            'content' => $document->content,
            'embedding' => $metadata['embedding'],
            'sourceType' => (string) ($metadata['sourceType'] ?? ''),
            'sourceName' => (string) ($metadata['sourceName'] ?? ''),
            'apps_id' => (int) ($metadata['apps_id'] ?? 0),
            'companies_id' => (int) ($metadata['companies_id'] ?? 0),
            'entity_type' => (string) ($metadata['entity_type'] ?? ''),
            'entity_id' => (int) ($metadata['entity_id'] ?? 0),
            'source_type' => (string) ($metadata['source_type'] ?? ''),
            'source_id' => (string) ($metadata['source_id'] ?? ''),
            'channel_names' => (string) ($metadata['channel_names'] ?? ''),
            'created_at' => (int) ($metadata['created_at'] ?? 0),
        ];
    }

    private function ensureCollection(): void
    {
        try {
            $schema = $this->client->collections[$this->collection]->retrieve();
            $embedding = collect($schema['fields'] ?? [])->firstWhere('name', 'embedding');

            if ((int) ($embedding['num_dim'] ?? 0) !== $this->vectorDimension) {
                throw new RuntimeException(sprintf(
                    'Typesense collection [%s] has an incompatible embedding dimension. '
                    . 'Configure a new knowledge collection before changing embedding models or dimensions.',
                    $this->collection,
                ));
            }
        } catch (ObjectNotFound) {
            $this->client->collections->create([
                'name' => $this->collection,
                'fields' => [
                    ['name' => 'content', 'type' => 'string'],
                    ['name' => 'sourceType', 'type' => 'string', 'facet' => true],
                    ['name' => 'sourceName', 'type' => 'string', 'facet' => true],
                    ['name' => 'embedding', 'type' => 'float[]', 'num_dim' => $this->vectorDimension],
                    ['name' => 'apps_id', 'type' => 'int64', 'facet' => true],
                    ['name' => 'companies_id', 'type' => 'int64', 'facet' => true],
                    ['name' => 'entity_type', 'type' => 'string', 'facet' => true],
                    ['name' => 'entity_id', 'type' => 'int64', 'facet' => true],
                    ['name' => 'source_type', 'type' => 'string', 'facet' => true],
                    ['name' => 'source_id', 'type' => 'string'],
                    ['name' => 'channel_names', 'type' => 'string', 'facet' => true],
                    ['name' => 'created_at', 'type' => 'int64', 'sort' => true],
                ],
            ]);
        }
    }

    private function collectionExists(): bool
    {
        try {
            $this->client->collections[$this->collection]->retrieve();

            return true;
        } catch (ObjectNotFound) {
            return false;
        }
    }

    /** @return array<int, string> */
    private function documentIds(
        KnowledgeScope $scope,
        string $sourceType,
        string $sourceName
    ): array {
        if (! $this->collectionExists()) {
            return [];
        }

        $filter = $this->sourceFilter($scope, $sourceType, $sourceName);
        $ids = [];
        $page = 1;

        do {
            $response = $this->client->collections[$this->collection]->documents->search([
                'q' => '*',
                'query_by' => 'content',
                'filter_by' => $filter,
                'include_fields' => 'id',
                'per_page' => 250,
                'page' => $page++,
            ]);
            $hits = $response['hits'] ?? [];
            foreach ($hits as $hit) {
                $ids[] = (string) $hit['document']['id'];
            }
        } while ($hits !== [] && count($ids) < (int) ($response['found'] ?? 0));

        return $ids;
    }

    private function sourceFilter(
        KnowledgeScope $scope,
        string $sourceType,
        string $sourceName
    ): string {
        return $scope->filter()
            . ' && sourceType:=' . KnowledgeScope::escapeFilterValue($sourceType)
            . ' && sourceName:=' . KnowledgeScope::escapeFilterValue($sourceName);
    }
}
