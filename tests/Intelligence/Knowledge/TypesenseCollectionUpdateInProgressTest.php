<?php

declare(strict_types=1);

namespace Tests\Intelligence\Knowledge;

use Kanvas\Intelligence\Knowledge\DataTransferObject\KnowledgeDocument;
use Kanvas\Intelligence\Knowledge\Exceptions\CollectionUpdateInProgressException;
use Kanvas\Intelligence\Knowledge\VectorStores\TypesenseKnowledgeStore;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;
use Typesense\Client;
use Typesense\Collection;
use Typesense\Collections;
use Typesense\Documents;
use Typesense\Exceptions\ObjectUnprocessable;

/**
 * KANVAS-ECOSYSTEM-6J9: the deploy that added `agent_id` and `users_id` had every worker try to add them
 * at once, and Typesense answered all but the first with a 422 that failed 314 index jobs in two
 * minutes. That answer means "wait", not "fail", and a worker only needs to check the schema once.
 */
class TypesenseCollectionUpdateInProgressTest extends TestCase
{
    public function testAConcurrentSchemaAlterIsAWaitNotAFailure(): void
    {
        $collection = $this->collectionMissingTheNewFields();
        $collection->shouldReceive('update')
            ->once()
            ->andThrow(new ObjectUnprocessable('Another collection update operation is in progress.'));

        $this->expectException(CollectionUpdateInProgressException::class);

        $this->store($collection, 'memory-alter-' . uniqid())->upsert([$this->document()]);
    }

    public function testAnyOther422StillSurfaces(): void
    {
        $collection = $this->collectionMissingTheNewFields();
        $collection->shouldReceive('update')
            ->once()
            ->andThrow(new ObjectUnprocessable('Field `agent_id` has an invalid type.'));

        $this->expectException(ObjectUnprocessable::class);

        $this->store($collection, 'memory-bad-field-' . uniqid())->upsert([$this->document()]);
    }

    public function testTheSchemaIsCheckedOncePerProcess(): void
    {
        $collection = $this->collectionMissingTheNewFields();
        $collection->shouldReceive('update')->once()->andReturn([]);
        $collection->shouldReceive('retrieve')->once()->andReturn(self::schemaMissingTheNewFields());
        $documents = Mockery::mock(Documents::class);
        $documents->shouldReceive('import')->twice()->andReturn([['success' => true]]);
        $collection->documents = $documents;

        $store = $this->store($collection, 'memory-once-' . uniqid());
        $store->upsert([$this->document()]);
        $store->upsert([$this->document()]);

        $this->assertTrue(true, 'Mockery enforces the once() expectations on close');
    }

    private function collectionMissingTheNewFields(): MockInterface
    {
        $collection = Mockery::mock(Collection::class);
        $collection->shouldReceive('retrieve')->andReturn(self::schemaMissingTheNewFields())->byDefault();

        return $collection;
    }

    /**
     * @return array<string, mixed>
     */
    private static function schemaMissingTheNewFields(): array
    {
        return [
            'fields' => [
                ['name' => 'content', 'type' => 'string'],
                ['name' => 'embedding', 'type' => 'float[]', 'num_dim' => 768],
                ['name' => 'apps_id', 'type' => 'int64'],
                ['name' => 'companies_id', 'type' => 'int64'],
            ],
        ];
    }

    private function store(MockInterface $collection, string $name): TypesenseKnowledgeStore
    {
        $collections = Mockery::mock(Collections::class);
        $collections->shouldReceive('offsetGet')->with($name)->andReturn($collection);

        $client = Mockery::mock(Client::class);
        $client->collections = $collections;

        return new TypesenseKnowledgeStore($client, $name, 768);
    }

    private function document(): KnowledgeDocument
    {
        return new KnowledgeDocument('doc-1', 'hello', [
            'embedding' => array_fill(0, 768, 0.1),
            'apps_id' => 1,
            'companies_id' => 1,
            'source_type' => 'conversation',
            'sourceType' => 'conversation',
            'sourceName' => 'thread',
            'entity_type' => '',
            'entity_id' => 0,
        ]);
    }
}
