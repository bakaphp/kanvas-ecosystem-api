<?php

declare(strict_types=1);

namespace Tests\Inventory;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Inventory\Products\Actions\CreateProductAction;
use Kanvas\Inventory\Products\DataTransferObject\Product as ProductDto;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Products\Models\ProductsWarehouses;
use Kanvas\Inventory\Regions\Models\Regions;
use Kanvas\Inventory\Support\Setup as InventorySetup;
use Kanvas\Inventory\Warehouses\Actions\CreateWarehouseAction;
use Kanvas\Inventory\Warehouses\DataTransferObject\Warehouses as WarehousesDto;
use Kanvas\Inventory\Warehouses\Models\Warehouses;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

class ProductsWarehousesCompositeKeyTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'ecosystem', 'inventory'];

    private Apps $kanvasApp;
    private Users $kanvasUser;
    private Products $product;
    private Warehouses $warehouseX;
    private Warehouses $warehouseY;

    protected function setUp(): void
    {
        parent::setUp();

        $this->kanvasApp = app(Apps::class);
        /** @var Users $user */
        $user = auth()->user();
        $this->kanvasUser = $user;
        $company = $user->getCurrentCompany();

        new InventorySetup($this->kanvasApp, $user, $company)->run();

        // CreateProductAction auto-creates a ProductsWarehouses row on the default
        // Setup warehouse (via VariantsWarehouseObserver); use fresh warehouses
        // here so the test rows don't collide on the composite PK.
        $this->product = $this->createProduct('PivotPWProduct');
        $this->warehouseX = $this->createWarehouse('PivotPWX');
        $this->warehouseY = $this->createWarehouse('PivotPWY');
    }

    public function testSaveAndFindRoundTripWithCompositeKey(): void
    {
        $row = new ProductsWarehouses();
        $row->products_id = $this->product->getId();
        $row->warehouses_id = $this->warehouseX->getId();
        $row->save();

        $found = ProductsWarehouses::find([
            $this->product->getId(),
            $this->warehouseX->getId(),
        ]);

        $this->assertNotNull($found);
        $this->assertSame($this->product->getId(), (int) $found->products_id);
        $this->assertSame($this->warehouseX->getId(), (int) $found->warehouses_id);
    }

    public function testUpdateScopesByCompositeKeyOnly(): void
    {
        $rowA = new ProductsWarehouses();
        $rowA->products_id = $this->product->getId();
        $rowA->warehouses_id = $this->warehouseX->getId();
        $rowA->vendor = 'vendor-A';
        $rowA->save();

        $rowB = new ProductsWarehouses();
        $rowB->products_id = $this->product->getId();
        $rowB->warehouses_id = $this->warehouseY->getId();
        $rowB->vendor = 'vendor-B';
        $rowB->save();

        $rowA->vendor = 'vendor-A-updated';
        $rowA->save();

        $aRefetched = ProductsWarehouses::find([
            $this->product->getId(),
            $this->warehouseX->getId(),
        ]);
        $bRefetched = ProductsWarehouses::find([
            $this->product->getId(),
            $this->warehouseY->getId(),
        ]);

        $this->assertNotNull($aRefetched);
        $this->assertNotNull($bRefetched);
        $this->assertSame('vendor-A-updated', $aRefetched->vendor);
        $this->assertSame('vendor-B', $bRefetched->vendor);
    }

    public function testSoftDeleteScopesByCompositeKeyOnly(): void
    {
        $rowA = new ProductsWarehouses();
        $rowA->products_id = $this->product->getId();
        $rowA->warehouses_id = $this->warehouseX->getId();
        $rowA->save();

        $rowB = new ProductsWarehouses();
        $rowB->products_id = $this->product->getId();
        $rowB->warehouses_id = $this->warehouseY->getId();
        $rowB->save();

        $rowA->delete();

        $aRefetched = ProductsWarehouses::withoutGlobalScopes()
            ->where('products_id', $this->product->getId())
            ->where('warehouses_id', $this->warehouseX->getId())
            ->first();
        $bRefetched = ProductsWarehouses::withoutGlobalScopes()
            ->where('products_id', $this->product->getId())
            ->where('warehouses_id', $this->warehouseY->getId())
            ->first();

        $this->assertNotNull($aRefetched);
        $this->assertNotNull($bRefetched);
        $this->assertTrue((bool) $aRefetched->is_deleted);
        $this->assertFalse((bool) $bRefetched->is_deleted);
    }

    private function createProduct(string $name): Products
    {
        $company = $this->kanvasUser->getCurrentCompany();

        return new CreateProductAction(
            new ProductDto(
                app: $this->kanvasApp,
                company: $company,
                user: $this->kanvasUser,
                name: $name . '-' . uniqid(),
            ),
            $this->kanvasUser
        )->execute();
    }

    private function createWarehouse(string $name): Warehouses
    {
        $company = $this->kanvasUser->getCurrentCompany();
        /** @var Regions $region */
        $region = Regions::fromApp($this->kanvasApp)
            ->fromCompany($company)
            ->firstOrFail();

        return new CreateWarehouseAction(
            new WarehousesDto(
                company: $company,
                app: $this->kanvasApp,
                user: $this->kanvasUser,
                region: $region,
                name: $name . '-' . uniqid(),
            ),
            $this->kanvasUser
        )->execute();
    }
}
