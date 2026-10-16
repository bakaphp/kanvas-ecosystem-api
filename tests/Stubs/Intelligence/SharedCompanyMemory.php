<?php

declare(strict_types=1);

namespace Tests\Stubs\Intelligence;

use Kanvas\Intelligence\Knowledge\VectorStores\KnowledgeVectorStore;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\VectorStore\MemoryVectorStore;
use NeuronAI\RAG\VectorStore\SearchRequest;

/**
 * The one in-memory company store every remembering stub in a test process writes to and reads from,
 * so a second session, or a second agent, can recall what the first was told without Typesense.
 */
final class SharedCompanyMemory
{
    public static ?MemoryVectorStore $store = null;

    public static function reset(): void
    {
        self::$store = self::newStore();
    }

    public static function store(): MemoryVectorStore
    {
        return self::$store ??= self::newStore();
    }

    public static function newStore(int $topK = 10): MemoryVectorStore
    {
        return new MemoryVectorStore(topK: $topK, schema: KnowledgeVectorStore::schema());
    }

    /**
     * Every document: the constant embedding scores them all alike.
     *
     * @return list<Document>
     */
    public static function all(?MemoryVectorStore $store = null, int $topK = 50): array
    {
        return ($store ?? self::store())->search(new SearchRequest(embedding: ConstantEmbeddingsProvider::VECTOR, topK: $topK));
    }

    /**
     * A tenant-pinned memory document of one kind, as the live paths write it.
     *
     * @param array<string, int|string> $extra
     */
    public static function document(
        string $content,
        string $kind,
        int $appsId,
        int $companiesId,
        ?int $createdAt = null,
        array $extra = [],
        string $thread = 'thread'
    ): Document {
        return new Document($content)
            ->setSourceType($kind)
            ->setSourceName($thread)
            ->setMetadata([
                'apps_id' => $appsId,
                'companies_id' => $companiesId,
                'source_type' => $kind,
                'created_at' => $createdAt ?? time(),
                ...$extra,
            ]);
    }
}
