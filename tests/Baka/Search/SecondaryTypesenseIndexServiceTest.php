<?php

declare(strict_types=1);

namespace Tests\Baka\Search;

use Baka\Search\SecondaryTypesenseIndexService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Inventory\Products\Models\Products;
use Tests\TestCase;
use Typesense\Client;
use Typesense\Collection;
use Typesense\Collections;
use Typesense\Document;
use Typesense\Documents;
use Typesense\Exceptions\ObjectNotFound;

final class SecondaryTypesenseIndexServiceTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'inventory'];

    public function testIndexEntityUpsertsTheEntityPayloadIntoTheGivenCollection(): void
    {
        $product = $this->createProduct();

        $documents = $this->createMock(Documents::class);
        $documents->expects($this->once())
            ->method('upsert')
            ->with($this->callback(fn (array $payload): bool => $payload['id'] === (string) $product->id));

        $client = $this->clientReturningDocuments($documents);

        new SecondaryTypesenseIndexService(app(Apps::class), $client)->indexEntity($product, 'popular_collection');
    }

    public function testRemoveEntityDeletesByTheEntitysIdField(): void
    {
        $product = $this->createProduct();

        $document = $this->createMock(Document::class);
        $document->expects($this->once())->method('delete');

        $documents = $this->createStub(Documents::class);
        $documents->method('offsetGet')->willReturn($document);

        $client = $this->clientReturningDocuments($documents);

        new SecondaryTypesenseIndexService(app(Apps::class), $client)->removeEntity($product, 'popular_collection');
    }

    public function testRemoveEntitySwallowsObjectNotFoundAsANoOp(): void
    {
        $product = $this->createProduct();

        $documents = $this->createStub(Documents::class);
        $documents->method('offsetGet')->willThrowException(new ObjectNotFound());

        $client = $this->clientReturningDocuments($documents);

        new SecondaryTypesenseIndexService(app(Apps::class), $client)->removeEntity($product, 'popular_collection');

        $this->addToAssertionCount(1);
    }

    private function clientReturningDocuments(Documents $documents): Client
    {
        $collection = $this->createStub(Collection::class);
        $collection->method('getDocuments')->willReturn($documents);

        $collections = $this->createStub(Collections::class);
        $collections->method('offsetGet')->willReturn($collection);

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
