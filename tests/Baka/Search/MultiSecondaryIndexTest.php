<?php

declare(strict_types=1);

namespace Tests\Baka\Search;

use Algolia\AlgoliaSearch\Api\SearchClient;
use Baka\Search\SecondaryAlgoliaIndexService;
use Baka\Search\SecondaryTypesenseIndexService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Inventory\Products\Models\Products;
use Tests\TestCase;
use Typesense\Client;
use Typesense\Collection;
use Typesense\Collections;
use Typesense\Documents;

/**
 * The point of a "secondary index" is that it's independent of every other target — nothing in
 * either service keys on "the" index for an entity, so the same entity can sit in as many indices,
 * across as many engines, as callers choose to push it to. These three tests exist because that
 * independence is an inference from the design, not something any single test above proves on its
 * own: each of the two services is tested calling it once, never twice against different targets.
 *
 * All three mock the SDK clients (Algolia/Typesense) rather than hitting a live backend — CI has no
 * Typesense/Algolia service to talk to, only the local docker-compose network does.
 */
final class MultiSecondaryIndexTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'inventory'];

    public function testEntitySavedInTwoAlgoliaIndexes(): void
    {
        $product = $this->createProduct();

        $client = $this->createMock(SearchClient::class);
        $client->expects($this->exactly(2))
            ->method('saveObject')
            ->with(
                $this->callback(fn (string $index): bool => in_array($index, ['algolia_index_a', 'algolia_index_b'], true)),
                $this->callback(fn (array $payload): bool => $payload['objectID'] === $product->uuid),
            );

        $service = new SecondaryAlgoliaIndexService(app(Apps::class), $client);
        $service->indexEntity($product, 'algolia_index_a');
        $service->indexEntity($product, 'algolia_index_b');
    }

    public function testEntitySavedInTwoTypesenseCollections(): void
    {
        $product = $this->createProduct();

        $client = $this->typesenseClientExpectingUpserts($product, [
            'typesense_collection_a',
            'typesense_collection_b',
        ]);

        $service = new SecondaryTypesenseIndexService(app(Apps::class), $client);
        $service->indexEntity($product, 'typesense_collection_a');
        $service->indexEntity($product, 'typesense_collection_b');
    }

    public function testEntitySavedInOneAlgoliaIndexAndOneTypesenseCollection(): void
    {
        $product = $this->createProduct();

        $algoliaClient = $this->createMock(SearchClient::class);
        $algoliaClient->expects($this->once())
            ->method('saveObject')
            ->with('algolia_mixed_index', $this->callback(fn (array $payload): bool => $payload['objectID'] === $product->uuid));

        $typesenseClient = $this->typesenseClientExpectingUpserts($product, ['typesense_mixed_collection']);

        new SecondaryAlgoliaIndexService(app(Apps::class), $algoliaClient)->indexEntity($product, 'algolia_mixed_index');
        new SecondaryTypesenseIndexService(app(Apps::class), $typesenseClient)->indexEntity($product, 'typesense_mixed_collection');
    }

    /**
     * @param array<int, string> $expectedCollections
     */
    private function typesenseClientExpectingUpserts(Products $product, array $expectedCollections): Client
    {
        $collectionsByName = [];

        foreach ($expectedCollections as $collectionName) {
            $documents = $this->createMock(Documents::class);
            $documents->expects($this->once())
                ->method('upsert')
                ->with($this->callback(fn (array $payload): bool => $payload['id'] === (string) $product->id));

            $collection = $this->createStub(Collection::class);
            $collection->method('getDocuments')->willReturn($documents);

            $collectionsByName[$collectionName] = $collection;
        }

        $collections = $this->createStub(Collections::class);
        $collections->method('offsetGet')->willReturnCallback(
            fn (string $name) => $collectionsByName[$name]
        );

        $client = $this->createStub(Client::class);
        $client->method('getCollections')->willReturn($collections);

        return $client;
    }

    private function createProduct(): Products
    {
        $company = Companies::factory()->create();

        /** @var Products $product */
        $product = Products::factory()
            ->withAppId(app(Apps::class)->getId())
            ->withCompanyId($company->getId())
            ->create(['is_published' => 1, 'is_deleted' => 0]);

        $product->load('variants');

        return $product;
    }
}
