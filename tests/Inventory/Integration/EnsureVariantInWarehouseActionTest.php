<?php

declare(strict_types=1);

namespace Tests\Inventory\Integration;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Inventory\Products\Factories\ProductFactory;
use Kanvas\Inventory\Regions\Models\Regions;
use Kanvas\Inventory\Status\Models\Status;
use Kanvas\Inventory\Support\Setup as InventorySetup;
use Kanvas\Inventory\Variants\Actions\AddToWarehouseAction;
use Kanvas\Inventory\Variants\Actions\EnsureVariantInWarehouseAction;
use Kanvas\Inventory\Variants\DataTransferObject\VariantsWarehouses as VariantsWarehousesDto;
use Kanvas\Inventory\Variants\Factories\VariantFactory;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Inventory\Variants\Models\VariantsWarehouses;
use Kanvas\Inventory\Warehouses\Actions\CreateWarehouseAction;
use Kanvas\Inventory\Warehouses\DataTransferObject\Warehouses as WarehousesDto;
use Kanvas\Inventory\Warehouses\Models\Warehouses;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

class EnsureVariantInWarehouseActionTest extends TestCase
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

        $this->stocked = $this->createWarehouse('EnsureStocked');
        $this->empty = $this->createWarehouse('EnsureEmpty');
        $this->variant = $this->createVariant();

        $this->stock($this->variant, $this->stocked, price: 12.5);
    }

    public function testAMissingRowIsCreatedEmptyAtTheVariantsCurrentPrice(): void
    {
        $row = new EnsureVariantInWarehouseAction($this->variant, $this->empty)->execute();

        $this->assertTrue($row->wasRecentlyCreated);
        $this->assertSame($this->empty->getId(), (int) $row->warehouses_id);
        $this->assertSame($this->variant->sku, $row->sku);
        $this->assertSame(0, (int) $row->quantity);
        $this->assertSame(12.5, (float) $row->price, 'A row created at 0.00 would read as free.');
    }

    public function testTheNewRowTakesTheReferenceRowsStatus(): void
    {
        $status = Status::getDefault($this->kanvasUser->getCurrentCompany(), $this->kanvasApp);
        VariantsWarehouses::query()
            ->where('products_variants_id', $this->variant->getId())
            ->where('warehouses_id', $this->stocked->getId())
            ->update(['status_id' => $status->getId()]);

        $row = new EnsureVariantInWarehouseAction($this->variant, $this->empty)->execute();

        $this->assertSame($status->getId(), (int) $row->status_id);
    }

    public function testTheDefaultRowIsTheReferenceWhenTheVariantIsStockedInSeveralPlaces(): void
    {
        $other = $this->createWarehouse('EnsureOther');
        $this->stock($this->variant, $other, price: 99.0);
        VariantsWarehouses::query()
            ->where('products_variants_id', $this->variant->getId())
            ->where('warehouses_id', $this->stocked->getId())
            ->update(['is_default' => 1]);

        $row = new EnsureVariantInWarehouseAction($this->variant, $this->empty)->execute();

        $this->assertSame(12.5, (float) $row->price);
    }

    public function testAVariantStockedNowhereStillGetsARow(): void
    {
        $orphan = $this->createVariant();

        $row = new EnsureVariantInWarehouseAction($orphan, $this->empty)->execute();

        $this->assertTrue($row->wasRecentlyCreated);
        $this->assertSame(0.0, (float) $row->price);
    }

    public function testAnExistingRowIsReturnedUntouched(): void
    {
        $row = new EnsureVariantInWarehouseAction($this->variant, $this->stocked)->execute();

        $this->assertFalse($row->wasRecentlyCreated, 'An existing row must never be recreated');
        $this->assertSame(12.5, (float) $row->price);
        $this->assertSame(3, (int) $row->quantity);
        $this->assertSame(
            1,
            VariantsWarehouses::query()
                ->where('products_variants_id', $this->variant->getId())
                ->where('warehouses_id', $this->stocked->getId())
                ->count()
        );
    }

    public function testFindingDoesNotCreate(): void
    {
        $this->assertNull(EnsureVariantInWarehouseAction::existing($this->variant, $this->empty));
        $this->assertNotNull(EnsureVariantInWarehouseAction::existing($this->variant, $this->stocked));
        $this->assertFalse(
            VariantsWarehouses::query()
                ->where('products_variants_id', $this->variant->getId())
                ->where('warehouses_id', $this->empty->getId())
                ->exists()
        );
    }

    private function createVariant(): Variants
    {
        $company = $this->kanvasUser->getCurrentCompany();
        $product = ProductFactory::new()
            ->withAppId($this->kanvasApp->getId())
            ->withCompanyId($company->getId())
            ->withUserId($this->kanvasUser->getId())
            ->create();

        return VariantFactory::new()
            ->withProductId($product->getId())
            ->withAppId($this->kanvasApp->getId())
            ->withCompanyId($company->getId())
            ->withUserId($this->kanvasUser->getId())
            ->create();
    }

    private function stock(Variants $variant, Warehouses $warehouse, float $price): void
    {
        new AddToWarehouseAction(
            $variant,
            $warehouse,
            new VariantsWarehousesDto(
                variant: $variant,
                warehouse: $warehouse,
                quantity: 3,
                price: $price,
                sku: (string) $variant->sku,
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
