<?php

declare(strict_types=1);

namespace Tests\Connectors\Yusen;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Yusen\Actions\BuildYusenDiscrepancyReportAction;
use Kanvas\Connectors\Yusen\Enums\ConfigurationEnum;
use Kanvas\Connectors\Yusen\Enums\DiscrepancyTypeEnum;
use Kanvas\Connectors\Yusen\Services\InventoryBalanceXmlParser;
use Kanvas\Inventory\Products\Actions\CreateProductAction;
use Kanvas\Inventory\Products\DataTransferObject\Product as ProductDto;
use Kanvas\Inventory\Regions\Models\Regions;
use Kanvas\Inventory\Support\Setup as InventorySetup;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Inventory\Variants\Models\VariantsWarehouses;
use Kanvas\Inventory\Warehouses\Actions\CreateWarehouseAction;
use Kanvas\Inventory\Warehouses\DataTransferObject\Warehouses as WarehousesDto;
use Kanvas\Inventory\Warehouses\Models\Warehouses;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

class YusenDiscrepancyReportTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'ecosystem', 'inventory'];

    private Apps $kanvasApp;
    private Users $user;
    private Warehouses $primaryWarehouse;

    protected function setUp(): void
    {
        parent::setUp();

        $this->kanvasApp = app(Apps::class);
        /** @var Users $user */
        $user = auth()->user();
        $this->user = $user;
        $company = $user->getCurrentCompany();

        new InventorySetup($this->kanvasApp, $user, $company)->run();

        $this->primaryWarehouse = $this->createWarehouse('YusenPrimary');

        $company->set(ConfigurationEnum::PRIMARY_WAREHOUSE_ID->value, $this->primaryWarehouse->getId());
        // The NetSuite leg needs live credentials; these cover the Kanvas leg.
        $company->set(ConfigurationEnum::RECONCILE_WITH_NETSUITE->value, false);
    }

    public function testReportsAQuantityMismatchAgainstThePrimaryWarehouse(): void
    {
        $variant = $this->createVariantWithBarcode('9990000000045');
        $this->stockPrimaryWarehouse($variant, 25);

        $row = $this->rowFor($this->runReport(), '9990000000045', DiscrepancyTypeEnum::QUANTITY_MISMATCH);

        $this->assertNotNull($row);
        $this->assertSame(1000.0, $row['yusen_quantity']);
        $this->assertSame(25.0, $row['compared_quantity']);
        $this->assertSame(975.0, $row['difference']);
        $this->assertSame('kanvas', $row['source']);
        $this->assertSame($variant->getId(), $row['variant_id']);
    }

    public function testWritesNothingToInventory(): void
    {
        $variant = $this->createVariantWithBarcode('9990000000045');
        $this->stockPrimaryWarehouse($variant, 25);

        $warehousesBefore = Warehouses::query()->fromApp($this->kanvasApp)->notDeleted()->count();
        // CreateProductAction already attaches the variant to the Setup default warehouse, so
        // measure the delta rather than an absolute count.
        $variantRowsBefore = VariantsWarehouses::where('products_variants_id', $variant->getId())->count();

        $this->runReport();

        // Yusen's count must not become Kanvas state: a second warehouse holding the same units
        // would double every SKU in Variants::setTotalQuantity(), which is what the agent
        // inventory tools quote. Recording it belongs in the movement ledger, not here.
        $this->assertSame(
            25,
            (int) VariantsWarehouses::where('products_variants_id', $variant->getId())
                ->where('warehouses_id', $this->primaryWarehouse->getId())
                ->first()
                ->quantity
        );
        $this->assertSame(
            $variantRowsBefore,
            VariantsWarehouses::where('products_variants_id', $variant->getId())->count()
        );
        $this->assertSame(
            $warehousesBefore,
            Warehouses::query()->fromApp($this->kanvasApp)->notDeleted()->count()
        );
    }

    public function testReportsItemsYusenHoldsThatKanvasHasNoVariantFor(): void
    {
        $report = $this->runReport();

        $missing = array_column(
            array_filter(
                $report['rows'],
                fn (array $row) => $row['type'] === DiscrepancyTypeEnum::MISSING_IN_KANVAS->value
            ),
            'item'
        );

        $this->assertContains('9990000000014', $missing);
        $this->assertContains('9990000078419', $missing);
    }

    public function testReportsStockKanvasHoldsThatYusenNeverMentioned(): void
    {
        $orphan = $this->createVariantWithBarcode('9999999999999');
        $this->stockPrimaryWarehouse($orphan, 40);

        $row = $this->rowFor($this->runReport(), '9999999999999', DiscrepancyTypeEnum::MISSING_IN_YUSEN);

        $this->assertNotNull($row);
        $this->assertSame(40.0, $row['compared_quantity']);
        $this->assertNull($row['yusen_quantity']);
    }

    public function testHonoursTheQuantityTolerance(): void
    {
        $variant = $this->createVariantWithBarcode('9990000000045');
        $this->stockPrimaryWarehouse($variant, 995);

        $this->assertNotNull(
            $this->rowFor($this->runReport(), '9990000000045', DiscrepancyTypeEnum::QUANTITY_MISMATCH)
        );

        $this->user->getCurrentCompany()->set(ConfigurationEnum::QUANTITY_TOLERANCE->value, 10);

        $this->assertNull(
            $this->rowFor($this->runReport(), '9990000000045', DiscrepancyTypeEnum::QUANTITY_MISMATCH)
        );
    }

    public function testCarriesTheFileSummaryAndTheMultiLotWarning(): void
    {
        $report = $this->runReport();

        $this->assertSame(4, $report['total_items']);
        $this->assertSame(5, $report['total_records']);
        $this->assertSame(3036.0, $report['total_quantity']);
        $this->assertSame(1, $report['multi_record_items']);
        $this->assertSame('11111111-2222-3333-4444-555555555555', $report['external_id']);
        $this->assertNull($report['netsuite_error']);
        $this->assertSame(count($report['rows']), $report['total_discrepancies']);
    }

    public function testCountsDiscrepanciesBySourceAndType(): void
    {
        $variant = $this->createVariantWithBarcode('9990000000045');
        $this->stockPrimaryWarehouse($variant, 25);

        $report = $this->runReport();

        $this->assertSame($report['total_discrepancies'], array_sum($report['by_source']));
        $this->assertSame($report['total_discrepancies'], array_sum($report['by_type']));
        $this->assertSame(1, $report['by_type'][DiscrepancyTypeEnum::QUANTITY_MISMATCH->value]);
    }

    private function runReport(): array
    {
        $balance = new InventoryBalanceXmlParser()->parseFile(__DIR__ . '/fixtures/item-balance.xml');

        return new BuildYusenDiscrepancyReportAction(
            $this->kanvasApp,
            $this->user->getCurrentCompany(),
            $balance,
        )->execute();
    }

    private function rowFor(array $report, string $item, DiscrepancyTypeEnum $type): ?array
    {
        foreach ($report['rows'] as $row) {
            if ($row['item'] === $item && $row['type'] === $type->value) {
                return $row;
            }
        }

        return null;
    }

    private function createVariantWithBarcode(string $barcode): Variants
    {
        $product = new CreateProductAction(
            new ProductDto(
                app: $this->kanvasApp,
                company: $this->user->getCurrentCompany(),
                user: $this->user,
                name: 'Yusen Test Product ' . uniqid(),
            ),
            $this->user
        )->execute();

        /** @var Variants $variant */
        $variant = $product->variants()->firstOrFail();
        $variant->barcode = $barcode;
        $variant->saveOrFail();

        return $variant;
    }

    private function stockPrimaryWarehouse(Variants $variant, int $quantity): void
    {
        $row = VariantsWarehouses::firstOrNew([
            'products_variants_id' => $variant->getId(),
            'warehouses_id' => $this->primaryWarehouse->getId(),
        ]);

        $row->sku = $variant->sku;
        $row->price = 0;
        $row->quantity = $quantity;
        $row->saveOrFail();
    }

    private function createWarehouse(string $name): Warehouses
    {
        $company = $this->user->getCurrentCompany();
        /** @var Regions $region */
        $region = Regions::fromApp($this->kanvasApp)
            ->fromCompany($company)
            ->firstOrFail();

        return new CreateWarehouseAction(
            new WarehousesDto(
                company: $company,
                app: $this->kanvasApp,
                user: $this->user,
                region: $region,
                name: $name . '-' . uniqid(),
            ),
            $this->user
        )->execute();
    }
}
