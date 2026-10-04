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
 * Every read and delete is pinned to app + company, either through
 * KnowledgeScope::filter() or through a compiled filter a caller built from the
 * document schema; searchByFilter() refuses an empty one. External agents add an
 * entity boundary, tenant-document reads pin entity_id = 0, and explicitly
 * internal organization reads may search every entity inside that company.
 */
final class TypesenseKnowledgeStore
{
    /**
     * Facets added after the collection shipped. Typesense adds a field to a live collection in place,
     * so an existing index gains them on the next write with no re-index.
     */
    private const array LATER_FIELDS = [
        ['name' => 'agent_id', 'type' => 'int64', 'facet' => true],
        ['name' => 'users_id', 'type' => 'int64', 'facet' => true],
    ];

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
        $this->deleteByFilter($scope->filter() . self::sourceSuffix($sourceType, $sourceName));
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
        $this->deleteByFilter(
            sprintf('apps_id:=%d && companies_id:=%d', $appId, $companyId) . self::sourceSuffix($sourceType, $sourceName)
        );
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
        return $this->searchByFilter($embedding, $scope->filter(), $topK, $minScore);
    }

    /**
     * @param array<int, float> $embedding
     * @param string $filterBy a compiled Typesense filter; never empty, every read of this collection is pinned to a tenant
     * @return array<int, array{content: string, sourceType: string, sourceName: string, score: float, metadata: array<string, mixed>}>
     */
    public function searchByFilter(
        array $embedding,
        string $filterBy,
        int $topK = 8,
        ?float $minScore = null
    ): array {
        if ($filterBy === '') {
            throw new RuntimeException('A knowledge search must be scoped: pass at least the tenant filters.');
        }

        // No existence pre-check: this runs on every recall of every tenant, and a collection that is not
        // there yet answers as a search with no hits.
        try {
            $response = $this->client->multiSearch->perform([
                'searches' => [[
                    'collection' => $this->collection,
                    'q' => '*',
                    'vector_query' => 'embedding:(' . (string) json_encode($embedding) . ', k:' . $topK . ')',
                    'filter_by' => $filterBy,
                    'exclude_fields' => 'embedding',
                    'per_page' => $topK,
                    'num_candidates' => max(50, $topK * 4),
                ]],
            ]);
        } catch (ObjectNotFound) {
            return [];
        }

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

    public function deleteByFilter(string $filterBy): void
    {
        if ($filterBy === '' || ! $this->collectionExists()) {
            return;
        }

        $this->client->collections[$this->collection]->documents->delete(['filter_by' => $filterBy]);
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
            'agent_id' => (int) ($metadata['agent_id'] ?? 0),
            'users_id' => (int) ($metadata['users_id'] ?? 0),
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

            $present = array_column($schema['fields'] ?? [], 'name');
            $missing = array_values(array_filter(
                self::LATER_FIELDS,
                static fn (array $field): bool => ! in_array($field['name'], $present, true),
            ));

            if ($missing !== []) {
                $this->client->collections[$this->collection]->update(['fields' => $missing]);
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
                    ...self::LATER_FIELDS,
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

        $filter = $scope->filter() . self::sourceSuffix($sourceType, $sourceName);
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

    private static function sourceSuffix(string $sourceType, string $sourceName): string
    {
        return ' && sourceType:=' . KnowledgeScope::escapeFilterValue($sourceType)
            . ' && sourceName:=' . KnowledgeScope::escapeFilterValue($sourceName);
    }
}
