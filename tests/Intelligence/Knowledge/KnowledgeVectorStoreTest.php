<?php

declare(strict_types=1);

namespace Tests\Intelligence\Knowledge;

use Baka\Search\SearchEngineResolver;
use Illuminate\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Knowledge\VectorStores\KnowledgeVectorStore;
use Kanvas\Intelligence\Knowledge\VectorStores\TypesenseKnowledgeStore;
use NeuronAI\Exceptions\VectorStoreException;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\Schema\DocumentSchemaException;
use NeuronAI\RAG\VectorStore\Filter\Filter;
use NeuronAI\RAG\VectorStore\Filter\FilterGroup;
use NeuronAI\RAG\VectorStore\SearchRequest;
use Tests\Stubs\Intelligence\ConstantEmbeddingsProvider;
use Tests\Stubs\Intelligence\SharedCompanyMemory;
use Tests\TestCase;
use Throwable;
use Typesense\Client;
use Typesense\Exceptions\ObjectNotFound;

class KnowledgeVectorStoreTest extends TestCase
{
    /** One collection per test process, so parallel workers on one cluster never share it. */
    private string $collection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->collection = 'knowledge_vector_store_test_' . Str::lower(Str::random(8));
    }

    public function testTheSchemaRefusesADocumentWithoutATenant(): void
    {
        $document = new Document('orphan')->setMetadata(['apps_id' => 1]);

        $this->expectException(DocumentSchemaException::class);
        $this->expectExceptionMessage('companies_id');

        KnowledgeVectorStore::schema()->validate($document);
    }

    public function testThePortableFilterVocabularyCompilesToTypesense(): void
    {
        $compiled = KnowledgeVectorStore::compile(FilterGroup::and(
            Filter::eq('apps_id', 1),
            Filter::eq('companies_id', 42),
            Filter::in('source_type', ['conversation', 'memory']),
            FilterGroup::or(Filter::gte('created_at', 1700000000), Filter::eq('entity_type', 'Kanvas\\Guild\\Leads\\Models\\Lead')),
        ));

        $this->assertSame(
            'apps_id:=1 && companies_id:=42 && source_type:=[`conversation`, `memory`] && (created_at:>=1700000000 || entity_type:=`Kanvas\\Guild\\Leads\\Models\\Lead`)',
            $compiled
        );
    }

    public function testAnUnscopedSearchIsRefused(): void
    {
        $store = new KnowledgeVectorStore($this->typesense());

        $this->expectException(VectorStoreException::class);

        $store->search(new SearchRequest(embedding: ConstantEmbeddingsProvider::VECTOR));
    }

    public function testDocumentsRoundTripThroughTheSharedCollectionByTenant(): void
    {
        $this->requireTypesense();

        $store = new KnowledgeVectorStore($this->typesense());
        $embeddings = new ConstantEmbeddingsProvider();

        try {
            $store->addDocuments($embeddings->embedDocuments([
                $this->memory('company-a', 1, "User: Who signs?\nAssistant: Ana."),
                $this->memory('company-b', 2, "User: Who signs?\nAssistant: Bob."),
            ]));

            $hits = $store->search(new SearchRequest(embedding: ConstantEmbeddingsProvider::VECTOR, filters: $this->tenant(1), topK: 5));

            $this->assertCount(1, $hits);
            $this->assertStringContainsString('Ana', $hits[0]->getContent());
            $this->assertSame('company-a', $hits[0]->getSourceName());
            $this->assertSame(1, $hits[0]->getMetadata()['companies_id']);
            $this->assertSame(7, $hits[0]->getMetadata()['agent_id'], 'The agent facet is stored and read back');

            $store->delete($this->tenant(1));

            $this->assertSame([], $store->search(new SearchRequest(embedding: ConstantEmbeddingsProvider::VECTOR, filters: $this->tenant(1))));
        } finally {
            try {
                $this->client()->collections[$this->collection]->delete();
            } catch (Throwable) {
            }
        }
    }

    private function tenant(int $companyId): FilterGroup
    {
        return FilterGroup::and(Filter::eq('apps_id', 1), Filter::eq('companies_id', $companyId));
    }

    private function memory(string $thread, int $companyId, string $content): Document
    {
        return SharedCompanyMemory::document(
            $content,
            'conversation',
            1,
            $companyId,
            extra: ['agent_id' => 7, 'users_id' => 9],
            thread: $thread,
        )->setId($thread . ':1');
    }

    private function typesense(): TypesenseKnowledgeStore
    {
        return new TypesenseKnowledgeStore(
            client: $this->client(),
            collection: $this->collection,
            vectorDimension: count(ConstantEmbeddingsProvider::VECTOR),
        );
    }

    private function client(): Client
    {
        return SearchEngineResolver::getTypesenseClient(app(Apps::class)->get('typesense_search_settings') ?? []);
    }

    private function requireTypesense(): void
    {
        try {
            $this->client()->collections[$this->collection]->retrieve();
        } catch (ObjectNotFound) {
        } catch (Throwable $e) {
            $this->markTestSkipped('Typesense cluster not reachable: ' . $e->getMessage());
        }
    }
}
