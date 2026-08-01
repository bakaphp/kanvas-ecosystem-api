<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Knowledge\Services;

use NeuronAI\RAG\Document;
use NeuronAI\RAG\VectorSimilarity;
use NeuronAI\RAG\VectorStore\VectorStoreInterface;
use Typesense\Client;
use Typesense\Exceptions\ObjectNotFound;

class LeadTypesenseVectorStore implements VectorStoreInterface
{
    public function __construct(
        private readonly Client $client,
        private readonly string $collection,
        private readonly int $vectorDimension,
        private readonly int $appId,
        private readonly int $companyId,
        private readonly int $leadId,
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
            $documents
        );
        $this->client->collections[$this->collection]->documents->import(
            $records,
            ['action' => 'upsert']
        );

        return $this;
    }

    public function deleteBySource(string $sourceType, string $sourceName): VectorStoreInterface
    {
        return $this->deleteBy($sourceType, $sourceName);
    }

    public function deleteBy(string $sourceType, ?string $sourceName = null): VectorStoreInterface
    {
        $this->ensureCollection();
        $filter = $this->tenantLeadFilter() . ' && sourceType:=`' . $sourceType . '`';
        if ($sourceName !== null) {
            $filter .= ' && sourceName:=`' . $sourceName . '`';
        }
        $this->client->collections[$this->collection]->documents->delete([
            'filter_by' => $filter,
        ]);

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
                'filter_by' => $this->tenantLeadFilter(),
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
            $document->score = VectorSimilarity::similarityFromDistance(
                $hit['vector_distance']
            );

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
            $this->client->collections[$this->collection]->retrieve();

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

    private function tenantLeadFilter(): string
    {
        // @todo Replace the Lead-only discriminator with a registered multi-entity scope.
        return sprintf(
            'apps_id:=%d && companies_id:=%d && entity_type:=lead && entity_id:=%d',
            $this->appId,
            $this->companyId,
            $this->leadId
        );
    }
}
