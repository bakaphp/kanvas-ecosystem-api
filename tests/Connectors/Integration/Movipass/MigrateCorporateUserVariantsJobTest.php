<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Movipass;

use Baka\Enums\StateEnums;
use Illuminate\Support\Facades\Auth;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Movipass\Enums\CustomFieldEnum;
use Kanvas\Connectors\Movipass\Jobs\MigrateCorporateUserVariantsJob;
use Kanvas\Currencies\Models\Currencies;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Regions\Actions\CreateRegionAction;
use Kanvas\Inventory\Regions\DataTransferObject\Region as RegionDto;
use Kanvas\Inventory\Variants\Actions\AddToWarehouseAction;
use Kanvas\Inventory\Variants\DataTransferObject\VariantsWarehouses as VariantsWarehousesDto;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Inventory\Variants\Models\VariantsWarehouses;
use Kanvas\Inventory\Warehouses\Actions\CreateWarehouseAction;
use Kanvas\Inventory\Warehouses\DataTransferObject\Warehouses as WarehousesDto;
use Kanvas\Inventory\Warehouses\Models\Warehouses;
use Kanvas\Regions\Models\Regions;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

final class MigrateCorporateUserVariantsJobTest extends TestCase
{
    private Apps $kanvasApp;
    private Users $kanvasUser;
    private Companies $sourceCompany;
    private Warehouses $sourceWarehouse;

    protected function setUp(): void
    {
        parent::setUp();

        $this->kanvasApp = app(Apps::class);
        /** @var Users $user */
        $user = Auth::user();
        $this->kanvasUser = $user;

        $this->sourceCompany = $this->createCompany();
        $this->sourceWarehouse = $this->createWarehouse($this->sourceCompany);
    }

    public function testMovesTheWarehouseLinkToTheTargetCompanyWarehouse(): void
    {
        $variant = $this->createVehicleVariant();
        $link = $this->linkToWarehouse($variant, $this->sourceWarehouse, 250.00);

        $targetCompany = $this->createCompany();
        $targetWarehouse = $this->createWarehouse($targetCompany);

        $this->migrateTo($targetCompany);

        $variant->refresh();
        $this->assertSame($targetCompany->getId(), (int) $variant->companies_id);
        $this->assertSame($targetCompany->getId(), (int) $variant->product->companies_id);

        $link->refresh();
        $this->assertSame($targetWarehouse->getId(), (int) $link->warehouses_id);
        $this->assertEqualsWithDelta(250.00, (float) $link->price, 0.001);

        $this->assertHasWarehouseInOwnCompany($variant);
    }

    public function testProvisionsAWarehouseWhenTheTargetCompanyHasNone(): void
    {
        $variant = $this->createVehicleVariant();
        $this->linkToWarehouse($variant, $this->sourceWarehouse, 0.00);

        $targetCompany = $this->createCompany();
        $this->assertNull($this->warehouseOf($targetCompany));

        $this->migrateTo($targetCompany);

        $this->assertNotNull($this->warehouseOf($targetCompany));
        $this->assertHasWarehouseInOwnCompany($variant->refresh());
    }

    public function testLinksVariantsThatNeverHadAWarehouse(): void
    {
        $variant = $this->createVehicleVariant();
        $this->assertSame(0, VariantsWarehouses::where('products_variants_id', $variant->getId())->count());

        $targetCompany = $this->createCompany();
        $this->createWarehouse($targetCompany);

        $this->migrateTo($targetCompany);

        $this->assertHasWarehouseInOwnCompany($variant->refresh());
    }

    public function testIsIdempotentWhenRetried(): void
    {
        $variant = $this->createVehicleVariant();
        $this->linkToWarehouse($variant, $this->sourceWarehouse, 99.00);

        $targetCompany = $this->createCompany();
        $this->createWarehouse($targetCompany);

        $this->migrateTo($targetCompany);
        $this->migrateTo($targetCompany);

        $this->assertSame(1, VariantsWarehouses::where('products_variants_id', $variant->getId())->count());
        $this->assertHasWarehouseInOwnCompany($variant->refresh());
    }

    /**
     * Mirrors the check OrderItem::fromMultiple runs before building a line item —
     * the variant must reach a live warehouse owned by the product's own company.
     */
    private function assertHasWarehouseInOwnCompany(Variants $variant): void
    {
        $this->assertTrue(
            $variant->variantWarehouses()
                ->whereHas(
                    'warehouse',
                    fn ($query) => $query->where('companies_id', $variant->product->companies_id)
                        ->where('is_deleted', 0)
                )
                ->exists(),
            "Variant {$variant->sku} has no warehouse in company {$variant->product->companies_id}"
        );
    }

    private function migrateTo(Companies $targetCompany): void
    {
        new MigrateCorporateUserVariantsJob(
            app: $this->kanvasApp,
            userId: $this->kanvasUser->getId(),
            sourceCompanyId: $this->sourceCompany->getId(),
            targetCompanyId: $targetCompany->getId(),
        )->handle();
    }

    private function createCompany(): Companies
    {
        $company = Companies::factory()->create([
            'users_id' => $this->kanvasUser->getId(),
        ]);

        $company->set(CustomFieldEnum::COMPANY_REGION_ID->value, $this->createRegion($company)->getId());

        return $company;
    }

    private function createRegion(Companies $company): Regions
    {
        return new CreateRegionAction(
            new RegionDto(
                company: $company,
                app: $this->kanvasApp,
                user: $this->kanvasUser,
                currency: Currencies::getBaseCurrency(),
                name: StateEnums::DEFAULT_NAME->getValue(),
                short_slug: StateEnums::DEFAULT_NAME->getValue(),
                is_default: StateEnums::YES->getValue(),
            ),
            $this->kanvasUser,
        )->execute();
    }

    private function createWarehouse(Companies $company): Warehouses
    {
        return new CreateWarehouseAction(
            new WarehousesDto(
                company: $company,
                app: $this->kanvasApp,
                user: $this->kanvasUser,
                region: $this->createRegion($company),
                name: StateEnums::DEFAULT_NAME->getValue(),
                location: StateEnums::DEFAULT_NAME->getValue(),
                is_default: true,
            ),
            $this->kanvasUser,
        )->execute();
    }

    private function warehouseOf(Companies $company): ?Warehouses
    {
        return Warehouses::query()
            ->fromApp($this->kanvasApp)
            ->fromCompany($company)
            ->notDeleted()
            ->first();
    }

    private function createVehicleVariant(): Variants
    {
        $product = Products::factory()->state([
            'apps_id' => $this->kanvasApp->getId(),
            'companies_id' => $this->sourceCompany->getId(),
            'users_id' => $this->kanvasUser->getId(),
        ])->create();

        return $product->variants()->firstOrFail();
    }

    private function linkToWarehouse(Variants $variant, Warehouses $warehouse, float $price): VariantsWarehouses
    {
        return new AddToWarehouseAction(
            $variant,
            $warehouse,
            VariantsWarehousesDto::viaRequest($variant, $warehouse, ['price' => $price]),
        )->execute();
    }
}
