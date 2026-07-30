<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Movipass;

use Illuminate\Support\Facades\Auth;
use Kanvas\Apps\Models\Apps;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Warehouses\Models\Warehouses;
use Kanvas\Regions\Models\Regions;
use Tests\GraphQL\Inventory\Traits\InventoryCases;
use Tests\TestCase;

final class CapacityRegionIsolationTest extends TestCase
{
    use InventoryCases;

    protected function setUp(): void
    {
        parent::setUp();

        if (getenv('GITHUB_ACTIONS')) {
            $this->markTestSkipped('Movipass capacity isolation tests are skipped in CI');
        }
    }

    public function testVariantWarehousesAreIndependentPerRegion(): void
    {
        $app = app(Apps::class);
        $user = Auth::user();
        $company = $user->getCurrentCompany();
        $defaultRegion = Regions::getDefault($company, $app);

        // Create a second region
        $secondRegionResponse = $this->createRegion([
            'name' => 'Capacity Test Region ' . fake()->word(),
            'slug' => 'capacity-test-' . fake()->unique()->slug(),
            'short_slug' => 'CT',
            'is_default' => 0,
            'currency_id' => 1,
        ])->json('data.createRegion');

        // Create warehouses in each region
        $warehouse1Response = $this->createWarehouses((string) $defaultRegion->getId(), [
            'regions_id' => (string) $defaultRegion->getId(),
            'name' => 'Warehouse Region 1 ' . fake()->word(),
            'location' => 'Location 1',
            'is_default' => false,
            'is_published' => true,
        ])->json('data.createWarehouse');

        $warehouse2Response = $this->createWarehouses($secondRegionResponse['id'], [
            'regions_id' => $secondRegionResponse['id'],
            'name' => 'Warehouse Region 2 ' . fake()->word(),
            'location' => 'Location 2',
            'is_default' => false,
            'is_published' => true,
        ])->json('data.createWarehouse');

        // Create a product
        $productResponse = $this->createProduct()->json('data.createProduct');
        $product = Products::fromApp($app)->find($productResponse['id']);
        $variant = $product->variants->first();

        $channelResponse = $this->createChannel()->json('data.createChannel');

        // Add variant to both warehouses with different quantities
        $this->addVariantToChannel(
            variantId: (string) $variant->id,
            channelId: $channelResponse['id'],
            warehouseData: ['id' => $warehouse1Response['id']],
        );

        $this->addVariantToWarehouse(
            variantId: (string) $variant->id,
            warehouseId: $warehouse1Response['id'],
            amount: 50,
        );

        $this->addVariantToWarehouse(
            variantId: (string) $variant->id,
            warehouseId: $warehouse2Response['id'],
            amount: 30,
        );

        // Verify variant-warehouse quantities are independent
        $variant->refresh();

        $vw1 = $variant->variantWarehouses()
            ->where('warehouses_id', $warehouse1Response['id'])
            ->first();

        $vw2 = $variant->variantWarehouses()
            ->where('warehouses_id', $warehouse2Response['id'])
            ->first();

        $this->assertNotNull($vw1);
        $this->assertNotNull($vw2);
        $this->assertEquals(50, $vw1->quantity);
        $this->assertEquals(30, $vw2->quantity);

        // Verify warehouses belong to different regions
        $wh1 = Warehouses::find($warehouse1Response['id']);
        $wh2 = Warehouses::find($warehouse2Response['id']);

        $this->assertEquals($defaultRegion->getId(), $wh1->regions_id);
        $this->assertEquals((int) $secondRegionResponse['id'], $wh2->regions_id);

        // Verify region filtering isolates products correctly
        $response1 = $this->graphQL('
            query($regionId: ID) {
                products(region_id: $regionId) {
                    data { id }
                }
            }
        ', ['regionId' => $defaultRegion->getId()]);

        $response1->assertSuccessful();
        $ids1 = collect($response1->json('data.products.data'))->pluck('id')->toArray();
        $this->assertContains((string) $product->id, $ids1);

        $response2 = $this->graphQL('
            query($regionId: ID) {
                products(region_id: $regionId) {
                    data { id }
                }
            }
        ', ['regionId' => $secondRegionResponse['id']]);

        $response2->assertSuccessful();
        $ids2 = collect($response2->json('data.products.data'))->pluck('id')->toArray();
        // Product should also appear in region 2 since it has a warehouse there
        $this->assertContains((string) $product->id, $ids2);
    }
}
