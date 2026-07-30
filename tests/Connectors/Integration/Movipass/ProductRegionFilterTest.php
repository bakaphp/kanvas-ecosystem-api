<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Movipass;

use Illuminate\Support\Facades\Auth;
use Kanvas\Apps\Models\Apps;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Regions\Models\Regions as KanvasRegions;
use Tests\GraphQL\Inventory\Traits\InventoryCases;
use Tests\TestCase;

final class ProductRegionFilterTest extends TestCase
{
    use InventoryCases;

    protected function setUp(): void
    {
        parent::setUp();

        if (getenv('GITHUB_ACTIONS')) {
            $this->markTestSkipped('Movipass region filter tests are skipped in CI');
        }
    }

    public function testProductsFilteredByRegionId(): void
    {
        $app = app(Apps::class);
        $user = Auth::user();
        $company = $user->getCurrentCompany();

        // Get default region
        $defaultRegion = KanvasRegions::getDefault($company, $app);

        // Create a warehouse in the default region and a product with variant
        $warehouseResponse = $this->createWarehouses((string) $defaultRegion->getId())->json('data.createWarehouse');
        $productResponse = $this->createProduct()->json('data.createProduct');
        $product = Products::fromApp($app)->find($productResponse['id']);
        $variant = $product->variants->first();

        $channelResponse = $this->createChannel()->json('data.createChannel');

        $this->addVariantToChannel(
            variantId: (string) $variant->id,
            channelId: $channelResponse['id'],
            warehouseData: ['id' => $warehouseResponse['id']],
        );

        $this->addVariantToWarehouse(
            variantId: (string) $variant->id,
            warehouseId: $warehouseResponse['id'],
            amount: 10,
        );

        // Query with the correct region_id — should find the product
        $response = $this->graphQL('
            query($regionId: ID) {
                products(region_id: $regionId) {
                    data {
                        id
                        name
                    }
                }
            }
        ', ['regionId' => $defaultRegion->getId()]);

        $response->assertSuccessful();
        $productIds = collect($response->json('data.products.data'))->pluck('id')->toArray();
        $this->assertContains((string) $product->id, $productIds);
    }

    public function testProductsFilteredByDifferentRegionExcludes(): void
    {
        $app = app(Apps::class);
        $user = Auth::user();
        $company = $user->getCurrentCompany();

        // Get default region
        $defaultRegion = KanvasRegions::getDefault($company, $app);

        // Create a second region
        $secondRegionResponse = $this->createRegion([
            'name' => 'Test Region ' . fake()->word(),
            'slug' => 'test-region-' . fake()->unique()->slug(),
            'short_slug' => 'TR',
            'is_default' => 0,
            'currency_id' => 1,
        ])->json('data.createRegion');

        // Create warehouse + product in the DEFAULT region
        $warehouseResponse = $this->createWarehouses((string) $defaultRegion->getId())->json('data.createWarehouse');
        $productResponse = $this->createProduct()->json('data.createProduct');
        $product = Products::fromApp($app)->find($productResponse['id']);
        $variant = $product->variants->first();

        $channelResponse = $this->createChannel()->json('data.createChannel');

        $this->addVariantToChannel(
            variantId: (string) $variant->id,
            channelId: $channelResponse['id'],
            warehouseData: ['id' => $warehouseResponse['id']],
        );

        $this->addVariantToWarehouse(
            variantId: (string) $variant->id,
            warehouseId: $warehouseResponse['id'],
            amount: 10,
        );

        // Query with the SECOND region — product should NOT appear
        $response = $this->graphQL('
            query($regionId: ID) {
                products(region_id: $regionId) {
                    data {
                        id
                        name
                    }
                }
            }
        ', ['regionId' => $secondRegionResponse['id']]);

        $response->assertSuccessful();
        $productIds = collect($response->json('data.products.data'))->pluck('id')->toArray();
        $this->assertNotContains((string) $product->id, $productIds);
    }

    public function testProductsWithoutRegionIdReturnsAll(): void
    {
        $app = app(Apps::class);
        $user = Auth::user();
        $company = $user->getCurrentCompany();
        $defaultRegion = KanvasRegions::getDefault($company, $app);

        // Create warehouse + product
        $warehouseResponse = $this->createWarehouses((string) $defaultRegion->getId())->json('data.createWarehouse');
        $productResponse = $this->createProduct()->json('data.createProduct');
        $product = Products::fromApp($app)->find($productResponse['id']);
        $variant = $product->variants->first();

        $channelResponse = $this->createChannel()->json('data.createChannel');

        $this->addVariantToChannel(
            variantId: (string) $variant->id,
            channelId: $channelResponse['id'],
            warehouseData: ['id' => $warehouseResponse['id']],
        );

        $this->addVariantToWarehouse(
            variantId: (string) $variant->id,
            warehouseId: $warehouseResponse['id'],
            amount: 10,
        );

        // Query without region_id — backward compat, should return products
        $response = $this->graphQL('
            query {
                products {
                    data {
                        id
                        name
                    }
                }
            }
        ');

        $response->assertSuccessful();
        $productIds = collect($response->json('data.products.data'))->pluck('id')->toArray();
        $this->assertContains((string) $product->id, $productIds);
    }
}
