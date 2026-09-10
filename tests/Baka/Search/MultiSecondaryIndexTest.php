<?php

declare(strict_types=1);

namespace Tests\Baka\Search;

use Algolia\AlgoliaSearch\Api\SearchClient;
use Baka\Search\SecondaryAlgoliaIndexService;
use Baka\Search\SecondaryTypesenseIndexService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Inventory\Products\Models\Products;
use Tests\TestCase;

/**
 * The point of a "secondary index" is that it's independent of every other target — nothing in
 * either service keys on "the" index for an entity, so the same entity can sit in as many indices,
 * across as many engines, as callers choose to push it to. These three tests exist because that
 * independence is an inference from the design, not something any single test above proves on its
 * own: each of the two services is tested calling it once, never twice against different targets.
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

        $this->createTypesenseCollection('typesense_collection_a');
        $this->createTypesenseCollection('typesense_collection_b');

        $app = app(Apps::class);

        try {
            $app->set('typesense_search_settings', $this->localTypesenseSettings());

            $service = new SecondaryTypesenseIndexService($app);
            $service->indexEntity($product, 'typesense_collection_a');
            $service->indexEntity($product, 'typesense_collection_b');

            $this->assertTypesenseCollectionHasDocument('typesense_collection_a', (string) $product->id);
            $this->assertTypesenseCollectionHasDocument('typesense_collection_b', (string) $product->id);
        } finally {
            $app->deleteHash('typesense_search_settings');
            $this->deleteTypesenseCollection('typesense_collection_a');
            $this->deleteTypesenseCollection('typesense_collection_b');
        }
    }

    public function testEntitySavedInOneAlgoliaIndexAndOneTypesenseCollection(): void
    {
        $product = $this->createProduct();

        $algoliaClient = $this->createMock(SearchClient::class);
        $algoliaClient->expects($this->once())
            ->method('saveObject')
            ->with('algolia_mixed_index', $this->callback(fn (array $payload): bool => $payload['objectID'] === $product->uuid));

        $this->createTypesenseCollection('typesense_mixed_collection');

        $app = app(Apps::class);

        try {
            $app->set('typesense_search_settings', $this->localTypesenseSettings());

            new SecondaryAlgoliaIndexService($app, $algoliaClient)->indexEntity($product, 'algolia_mixed_index');
            new SecondaryTypesenseIndexService($app)->indexEntity($product, 'typesense_mixed_collection');

            $this->assertTypesenseCollectionHasDocument('typesense_mixed_collection', (string) $product->id);
        } finally {
            $app->deleteHash('typesense_search_settings');
            $this->deleteTypesenseCollection('typesense_mixed_collection');
        }
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

    private function localTypesenseSettings(): array
    {
        return [
            'typesense_api_key' => 'xyz',
            'typesense_nodes' => [['host' => 'typesense', 'port' => 8108, 'path' => '/', 'protocol' => 'http']],
        ];
    }

    private function createTypesenseCollection(string $name): void
    {
        Http::withHeaders(['X-TYPESENSE-API-KEY' => 'xyz'])
            ->post('http://typesense:8108/collections', [
                'name' => $name,
                'fields' => [['name' => '.*', 'type' => 'auto']],
                'enable_nested_fields' => true,
            ]);
    }

    private function deleteTypesenseCollection(string $name): void
    {
        Http::withHeaders(['X-TYPESENSE-API-KEY' => 'xyz'])->delete("http://typesense:8108/collections/{$name}");
    }

    private function assertTypesenseCollectionHasDocument(string $collection, string $documentId): void
    {
        $response = Http::withHeaders(['X-TYPESENSE-API-KEY' => 'xyz'])
            ->get("http://typesense:8108/collections/{$collection}/documents/{$documentId}");

        $this->assertTrue($response->successful(), "Expected document {$documentId} in collection {$collection}");
    }
}
