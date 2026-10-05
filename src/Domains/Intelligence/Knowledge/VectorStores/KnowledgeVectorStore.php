<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Knowledge\VectorStores;

use Kanvas\Intelligence\Knowledge\DataTransferObject\KnowledgeDocument;
use NeuronAI\Exceptions\VectorStoreException;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\Schema\DocumentField;
use NeuronAI\RAG\Schema\DocumentSchema;
use NeuronAI\RAG\VectorStore\Compilers\TypesenseFilterCompiler;
use NeuronAI\RAG\VectorStore\Filter\FilterExpression;
use NeuronAI\RAG\VectorStore\SearchRequest;
use NeuronAI\RAG\VectorStore\VectorStoreInterface;
use Override;

/**
 * The shared knowledge collection as a Neuron vector store, so Neuron's own retrieval and ingestion
 * nodes can read and write it with the portable filter vocabulary. The collection keeps one shape for
 * every writer; this only maps a Document to that shape and a FilterExpression to `filter_by`.
 *
 * A search without filters is refused: every read of this collection is pinned to a tenant pair, and
 * the agent's retrieval scope is what pins it.
 */
final class KnowledgeVectorStore implements VectorStoreInterface
{
    private static ?DocumentSchema $schema = null;

    public function __construct(
        private readonly TypesenseKnowledgeStore $store,
        private readonly int $topK = 8,
    ) {
    }

    #[Override]
    public function getSchema(): DocumentSchema
    {
        return self::schema();
    }

    public static function schema(): DocumentSchema
    {
        return self::$schema ??= DocumentSchema::of(
            DocumentField::integer('apps_id')->required()->filterable(),
            DocumentField::integer('companies_id')->required()->filterable(),
            DocumentField::string('entity_type')->filterable(),
            DocumentField::integer('entity_id')->filterable(),
            DocumentField::string('source_type')->filterable(),
            DocumentField::string('source_id'),
            DocumentField::string('channel_names')->filterable(),
            DocumentField::integer('agent_id')->filterable(),
            DocumentField::integer('users_id')->filterable(),
            DocumentField::integer('created_at')->filterable(),
        );
    }

    #[Override]
    public function addDocument(Document $document): VectorStoreInterface
    {
        return $this->addDocuments([$document]);
    }

    /**
     * @param Document[] $documents
     */
    #[Override]
    public function addDocuments(array $documents): VectorStoreInterface
    {
        $schema = $this->getSchema();
        $records = [];

        foreach ($documents as $document) {
            $schema->validate($document);

            if ($document->getEmbedding() === null) {
                throw new VectorStoreException("Document {$document->getId()} has no embedding.");
            }

            $records[] = new KnowledgeDocument(
                id: (string) $document->getId(),
                content: $document->getContent(),
                metadata: [
                    ...$document->getMetadata(),
                    'sourceType' => $document->getSourceType(),
                    'sourceName' => $document->getSourceName(),
                    'embedding' => $document->getEmbedding(),
                ],
            );
        }

        $this->store->upsert($records);

        return $this;
    }

    #[Override]
    public function delete(FilterExpression $filters): VectorStoreInterface
    {
        $this->store->deleteByFilter(self::compile($filters));

        return $this;
    }

    /**
     * @return list<Document>
     */
    #[Override]
    public function search(SearchRequest $request): iterable
    {
        if ($request->filters === null) {
            throw new VectorStoreException('A knowledge search must be scoped: pass at least the tenant filters.');
        }

        $hits = $this->store->searchByFilter(
            $request->embedding,
            self::compile($request->filters),
            $request->topK ?? $this->topK,
        );

        return array_map(self::toDocument(...), $hits);
    }

    public static function compile(FilterExpression $filters): string
    {
        return new TypesenseFilterCompiler()->compile($filters);
    }

    /**
     * @param array{content: string, sourceType: string, sourceName: string, score: float, metadata: array<string, mixed>} $hit
     */
    private static function toDocument(array $hit): Document
    {
        $record = $hit['metadata'];
        $metadata = [];

        foreach (self::schema()->fields() as $field) {
            if (array_key_exists($field->getName(), $record)) {
                $metadata[$field->getName()] = $record[$field->getName()];
            }
        }

        return new Document($hit['content'])
            ->setId((string) ($record['id'] ?? ''))
            ->setSourceType($hit['sourceType'])
            ->setSourceName($hit['sourceName'])
            ->setScore($hit['score'])
            ->setMetadata($metadata);
    }
}
