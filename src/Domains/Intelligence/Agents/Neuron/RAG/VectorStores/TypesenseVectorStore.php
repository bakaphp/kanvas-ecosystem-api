<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\RAG\VectorStores;

use Kanvas\Intelligence\Knowledge\DataTransferObject\KnowledgeEntity;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\VectorSimilarity;
use NeuronAI\RAG\VectorStore\VectorStoreInterface;
use RuntimeException;
use Typesense\Client;
use Typesense\Exceptions\ObjectNotFound;

class TypesenseVectorStore implements VectorStoreInterface
{
    public function __construct(
        private readonly Client $client,
        private readonly string $collection,
        private readonly int $vectorDimension,
        private readonly KnowledgeEntity $entity,
        private readonly int $topK = 8,
    ) {
    }

    public function addDocument(Document $document): VectorStoreInterface
    {
        return $this->addDocuments([$document]);
    }

    public function addDocuments(array $documents): VectorStoreInterface
    {
        if ($documents === []) {
            return $this;
        }

        $this->ensureCollection();
        $records = array_map(
            fn (Document $document): array => [
                'id' => (string) $document->getId(),
                'content' => $document->getContent(),
                'embedding' => $document->getEmbedding(),
                'sourceType' => $document->getSourceType(),
                'sourceName' => $document->getSourceName(),
                ...$document->metadata,
            ],
            $documents,
        );
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

        return $this;
    }

    /**
     * Import the new snapshot before removing stale records. A failed embedding/import therefore
     * leaves the previous snapshot queryable instead of creating a knowledge gap.
     *
     * @param list<Document> $documents
     */
    public function replaceDocuments(array $documents, string $sourceType, string $sourceName): self
    {
        $existingIds = $this->documentIds($sourceType, $sourceName);
        $this->addDocuments($documents);

        $currentIds = array_fill_keys(
            array_map(fn (Document $document): string => (string) $document->getId(), $documents),
            true,
        );

        foreach ($existingIds as $id) {
            if (! isset($currentIds[$id])) {
                $this->client->collections[$this->collection]->documents[$id]->delete();
            }
        }

        return $this;
    }

    public function deleteBySource(string $sourceType, string $sourceName): VectorStoreInterface
    {
        return $this->deleteBy($sourceType, $sourceName);
    }

    public function deleteBy(string $sourceType, ?string $sourceName = null): VectorStoreInterface
    {
        $this->ensureCollection();
        $filter = $this->entityFilter() . ' && sourceType:=' . $this->filterValue($sourceType);
        if ($sourceName !== null) {
            $filter .= ' && sourceName:=' . $this->filterValue($sourceName);
        }

        $this->client->collections[$this->collection]->documents->delete(['filter_by' => $filter]);

        return $this;
    }

    public function similaritySearch(array $embedding): iterable
    {
        $this->ensureCollection();
        $response = $this->client->multiSearch->perform([
            'searches' => [[
                'collection' => $this->collection,
                'q' => '*',
                'vector_query' => 'embedding:(' . json_encode($embedding) . ')',
                'filter_by' => $this->entityFilter(),
                'exclude_fields' => 'embedding',
                'per_page' => $this->topK,
                'num_candidates' => max(50, $this->topK * 4),
            ]],
        ]);

        return array_map(function (array $hit): Document {
            $item = $hit['document'];
            $document = new Document($item['content']);
            $document->id = $item['id'];
            $document->sourceType = $item['sourceType'];
            $document->sourceName = $item['sourceName'];
            $document->score = VectorSimilarity::similarityFromDistance($hit['vector_distance']);

            foreach (['source_type', 'source_id', 'channel_names', 'created_at'] as $field) {
                if (isset($item[$field])) {
                    $document->addMetadata($field, $item[$field]);
                }
            }

            return $document;
        }, $response['results'][0]['hits'] ?? []);
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

            return;
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

    private function entityFilter(): string
    {
        return sprintf(
            'apps_id:=%d && companies_id:=%d && entity_type:=%s && entity_id:=%d',
            $this->entity->appId,
            $this->entity->companyId,
            $this->filterValue($this->entity->type),
            $this->entity->id,
        );
    }

    /** @return list<string> */
    private function documentIds(string $sourceType, string $sourceName): array
    {
        $this->ensureCollection();
        $filter = $this->entityFilter()
            . ' && sourceType:=' . $this->filterValue($sourceType)
            . ' && sourceName:=' . $this->filterValue($sourceName);
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

    private function filterValue(string $value): string
    {
        return '`' . str_replace(['\\', '`'], ['\\\\', '\\`'], $value) . '`';
    }
}
