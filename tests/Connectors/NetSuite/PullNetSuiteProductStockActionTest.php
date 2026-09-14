<?php

declare(strict_types=1);

namespace Tests\Connectors\NetSuite;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\NetSuite\Actions\PullNetSuiteProductStockAction;
use Kanvas\Inventory\Products\Factories\ProductFactory;
use Kanvas\Inventory\Regions\Models\Regions;
use Kanvas\Inventory\Support\Setup as InventorySetup;
use Kanvas\Inventory\Variants\Actions\AddToWarehouseAction;
use Kanvas\Inventory\Variants\DataTransferObject\VariantsWarehouses as VariantsWarehousesDto;
use Kanvas\Inventory\Variants\Factories\VariantFactory;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Inventory\Variants\Models\VariantsWarehouses;
use Kanvas\Inventory\Warehouses\Actions\CreateWarehouseAction;
use Kanvas\Inventory\Warehouses\DataTransferObject\Warehouses as WarehousesDto;
use Kanvas\Inventory\Warehouses\Models\Warehouses;
use Kanvas\Users\Models\Users;
use ReflectionMethod;
use Tests\TestCase;

/**
 * A warehouse newly mapped to a NetSuite location has no stock rows at all, so the sync has nothing
 * to write into and reports every SKU as missing. Creating those rows is opt-in because doing it by
 * default cannot be undone by fixing a bad mapping.
 */
class PullNetSuiteProductStockActionTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'ecosystem', 'inventory'];

    private Apps $kanvasApp;
    private Users $kanvasUser;
    private Variants $variant;
    private Warehouses $stocked;
    private Warehouses $empty;

    protected function setUp(): void
    {
        parent::setUp();

        $this->kanvasApp = app(Apps::class);
        /** @var Users $user */
        $user = auth()->user();
        $this->kanvasUser = $user;
        $company = $user->getCurrentCompany();

        new InventorySetup($this->kanvasApp, $user, $company)->run();

        $this->stocked = $this->createWarehouse('NsStocked');
        $this->empty = $this->createWarehouse('NsEmpty');

        $product = ProductFactory::new()
            ->withAppId($this->kanvasApp->getId())
            ->withCompanyId($company->getId())
            ->withUserId($user->getId())
            ->create();

        $this->variant = VariantFactory::new()
            ->withProductId($product->getId())
            ->withAppId($this->kanvasApp->getId())
            ->withCompanyId($company->getId())
            ->withUserId($user->getId())
            ->create();

        $this->addToWarehouse($this->stocked, 12.5);
    }

    public function testAWarehouseWithNoRowIsReportedRatherThanCreatedByDefault(): void
    {
        $this->assertNull($this->resolve($this->empty, createMissing: false));

        $this->assertFalse(
            $this->rowExists($this->empty),
            'The default path must not write a row into an unmapped warehouse'
        );
    }

    public function testTheRowIsCreatedWhenTheCallerAsksForIt(): void
    {
        $row = $this->resolve($this->empty, createMissing: true);

        $this->assertNotNull($row);
        $this->assertTrue($row->wasRecentlyCreated);
        $this->assertSame($this->empty->getId(), (int) $row->warehouses_id);
        $this->assertSame($this->variant->sku, $row->sku);
    }

    public function testACreatedRowInheritsPriceFromWhereTheVariantIsAlreadyStocked(): void
    {
        // A row created at 0.00 would read as free everywhere price is surfaced.
        $row = $this->resolve($this->empty, createMissing: true);

        $this->assertSame(12.5, (float) $row->price);
    }

    public function testAVariantStockedNowhereStillGetsARow(): void
    {
        // No reference row to copy a price from — the first warehouse a variant lands in has to
        // work, not warn and fall over on a null read.
        $orphan = VariantFactory::new()
            ->withProductId($this->variant->products_id)
            ->withAppId($this->kanvasApp->getId())
            ->withCompanyId($this->kanvasUser->getCurrentCompany()->getId())
            ->withUserId($this->kanvasUser->getId())
            ->create();

        $action = new PullNetSuiteProductStockAction(
            $this->kanvasApp,
            $this->kanvasUser->getCurrentCompany(),
            $this->kanvasUser
        );

        $method = new ReflectionMethod($action, 'resolveVariantWarehouse');
        $method->setAccessible(true);
        $row = $method->invoke($action, $orphan, $this->empty, true);

        $this->assertNotNull($row);
        $this->assertTrue($row->wasRecentlyCreated);
        $this->assertSame(0.0, (float) $row->price);
    }

    public function testAnExistingRowIsReturnedUntouched(): void
    {
        $row = $this->resolve($this->stocked, createMissing: true);

        $this->assertFalse($row->wasRecentlyCreated, 'An existing row must never be recreated');
        $this->assertSame(12.5, (float) $row->price);
        $this->assertSame(
            1,
            VariantsWarehouses::query()
                ->where('products_variants_id', $this->variant->getId())
                ->where('warehouses_id', $this->stocked->getId())
                ->count()
        );
    }

    private function resolve(Warehouses $warehouse, bool $createMissing): ?VariantsWarehouses
    {
        $action = new PullNetSuiteProductStockAction(
            $this->kanvasApp,
            $this->kanvasUser->getCurrentCompany(),
            $this->kanvasUser
        );

        $method = new ReflectionMethod($action, 'resolveVariantWarehouse');
        $method->setAccessible(true);

        return $method->invoke($action, $this->variant, $warehouse, $createMissing);
    }

    private function rowExists(Warehouses $warehouse): bool
    {
        return VariantsWarehouses::query()
            ->where('products_variants_id', $this->variant->getId())
            ->where('warehouses_id', $warehouse->getId())
            ->exists();
    }

    private function addToWarehouse(Warehouses $warehouse, float $price): void
    {
        new AddToWarehouseAction(
            $this->variant,
            $warehouse,
            new VariantsWarehousesDto(
                variant: $this->variant,
                warehouse: $warehouse,
                quantity: 3,
                price: $price,
                sku: (string) $this->variant->sku,
            )
        )->execute();
    }

    private function createWarehouse(string $name): Warehouses
    {
        $company = $this->kanvasUser->getCurrentCompany();
        /** @var Regions $region */
        $region = Regions::fromApp($this->kanvasApp)->fromCompany($company)->firstOrFail();

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
