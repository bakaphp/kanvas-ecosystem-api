<?php

declare(strict_types=1);

namespace Tests\Inventory\Products;

use Algolia\AlgoliaSearch\Api\SearchClient;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Products\Services\SecondaryAlgoliaIndexService;
use Tests\TestCase;

final class SecondaryAlgoliaIndexServiceTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'inventory'];

    public function testIndexProductWritesTheProductPayloadToTheGivenIndex(): void
    {
        $product = $this->createProduct();

        $client = $this->createMock(SearchClient::class);
        $client->expects($this->once())
            ->method('saveObject')
            ->with('popular_index', $this->callback(fn (array $payload): bool => $payload['objectID'] === $product->uuid));

        new SecondaryAlgoliaIndexService(app(Apps::class), $client)->indexProduct($product, 'popular_index');
    }

    public function testRemoveProductDeletesByProductUuid(): void
    {
        $product = $this->createProduct();

        $client = $this->createMock(SearchClient::class);
        $client->expects($this->once())
            ->method('deleteObject')
            ->with('popular_index', $product->uuid);

        new SecondaryAlgoliaIndexService(app(Apps::class), $client)->removeProduct($product, 'popular_index');
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
