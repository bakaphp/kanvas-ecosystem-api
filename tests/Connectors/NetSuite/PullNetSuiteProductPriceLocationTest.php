<?php

declare(strict_types=1);

namespace Tests\Connectors\NetSuite;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\NetSuite\Actions\PullNetSuiteProductPriceAction;
use Kanvas\Connectors\NetSuite\Enums\ConfigurationEnum;
use Kanvas\Connectors\NetSuite\Enums\CustomFieldEnum;
use Kanvas\Connectors\NetSuite\Services\NetSuiteProductSearchService;
use Kanvas\Connectors\NetSuite\Services\NetSuiteProductService;
use Kanvas\Inventory\Products\Factories\ProductFactory;
use Kanvas\Inventory\Support\Setup as InventorySetup;
use Kanvas\Inventory\Variants\Actions\AddToWarehouseAction;
use Kanvas\Inventory\Variants\DataTransferObject\VariantsWarehouses as VariantsWarehousesData;
use Kanvas\Inventory\Variants\Factories\VariantFactory;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Inventory\Variants\Models\VariantsWarehouses;
use Kanvas\Inventory\Warehouses\Actions\CreateWarehouseAction;
use Kanvas\Inventory\Warehouses\DataTransferObject\Warehouses as WarehousesData;
use Kanvas\Inventory\Warehouses\Models\Warehouses;
use Kanvas\Users\Models\Users;
use NetSuite\Classes\InventoryItem;
use ReflectionProperty;
use Tests\Connectors\Traits\HasThrowawayCompany;
use Tests\TestCase;

/**
 * The quantity a refresh writes on a row has to be the one of the location that row's warehouse
 * mirrors. Reading the company default instead put one location's stock in another's warehouse.
 */
class PullNetSuiteProductPriceLocationTest extends TestCase
{
    use DatabaseTransactions;
    use HasThrowawayCompany;

    protected array $connectionsToTransact = ['mysql', 'ecosystem'];

    private Apps $kanvasApp;
    private Users $kanvasUser;
    private Companies $company;
    private Warehouses $yusen;
    private Warehouses $aero;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var Users $user */
        $user = auth()->user();
        $this->kanvasUser = $user;
        $this->kanvasApp = app(Apps::class);
        $this->company = $this->freshCompany();
        new InventorySetup($this->kanvasApp, $user, $this->company)->run();

        $this->yusen = Warehouses::getDefault($this->company, $this->kanvasApp);
        $this->yusen->set(CustomFieldEnum::NET_SUITE_LOCATION_ID->value, '7');
        $this->aero = $this->createWarehouse('Aero');
        $this->aero->set(CustomFieldEnum::NET_SUITE_LOCATION_ID->value, '4');
        $this->company->set(ConfigurationEnum::NET_SUITE_DEFAULT_WAREHOUSE->value, '7');
    }

    public function testTheRefreshReadsTheLocationOfTheWarehouseTheRowSitsIn(): void
    {
        $variant = $this->variantStockedIn($this->aero);

        $location = $this->refresh($variant);

        $this->assertSame('4', $location);
        $this->assertSame(33, (int) $this->row($variant, $this->aero)->quantity);
    }

    public function testAValueOnTheRowItselfStillWins(): void
    {
        $variant = $this->variantStockedIn($this->aero);
        $this->row($variant, $this->aero)->set(CustomFieldEnum::NET_SUITE_LOCATION_ID->value, '9');

        $this->assertSame('9', $this->refresh($variant));
    }

    public function testAnUnmappedWarehouseFallsBackToTheCompanyDefault(): void
    {
        $variant = $this->variantStockedIn($this->createWarehouse('Unmapped'));

        $this->assertSame('7', $this->refresh($variant));
    }

    /**
     * @return string the location the refresh asked NetSuite for
     */
    private function refresh(Variants $variant): string
    {
        $action = new PullNetSuiteProductPriceAction($this->kanvasApp, $this->company, $this->kanvasUser);

        new ReflectionProperty($action, 'productService')->setValue(
            $action,
            new class ($this->kanvasApp, $this->company) extends NetSuiteProductService {
                public function searchProductByItemNumber(string|int $itemNumber): array
                {
                    return [(object) ['internalId' => '55']];
                }

                public function getProductById(int|string $productId): InventoryItem
                {
                    return new InventoryItem();
                }

                public function getProductPrice(InventoryItem $product, string $priceLevel = 'MSRP Price'): float
                {
                    return 12.5;
                }
            }
        );

        $search = new class ($this->kanvasApp, $this->company) extends NetSuiteProductSearchService {
            public ?string $location = null;

            public function searchProductByItemNumber(
                string|int $itemNumber,
                string|int $locationId,
                string|int $savedSearchId = '574'
            ): array {
                $this->location = (string) $locationId;

                return [
                    [
                        'mapPrice' => 8,
                        'colorCode' => 'B00',
                        'minimumQuantity' => 1,
                        'quantityAvailable' => 33,
                        'moq' => 1,
                    ],
                ];
            }
        };
        new ReflectionProperty($action, 'searchService')->setValue($action, $search);

        $result = $action->execute((string) $variant->barcode);

        $this->assertArrayNotHasKey('error', $result);

        return (string) $search->location;
    }

    private function row(Variants $variant, Warehouses $warehouse): VariantsWarehouses
    {
        return VariantsWarehouses::query()
            ->where('products_variants_id', $variant->getId())
            ->where('warehouses_id', $warehouse->getId())
            ->firstOrFail();
    }

    private function createWarehouse(string $name): Warehouses
    {
        return new CreateWarehouseAction(
            new WarehousesData(
                company: $this->company,
                app: $this->kanvasApp,
                user: $this->kanvasUser,
                region: $this->yusen->region,
                name: $name,
            ),
            $this->kanvasUser
        )->execute();
    }

    private function variantStockedIn(Warehouses $warehouse): Variants
    {
        $product = ProductFactory::new()
            ->withAppId($this->kanvasApp->getId())
            ->withCompanyId($this->company->getId())
            ->withUserId($this->kanvasUser->getId())
            ->create();

        $variant = VariantFactory::new()
            ->withProductId($product->getId())
            ->withAppId($this->kanvasApp->getId())
            ->withCompanyId($this->company->getId())
            ->withUserId($this->kanvasUser->getId())
            ->create();
        $variant->barcode = 'NS-' . Str::random(12);
        $variant->saveOrFail();

        new AddToWarehouseAction(
            $variant,
            $warehouse,
            new VariantsWarehousesData(
                variant: $variant,
                warehouse: $warehouse,
                quantity: 1,
                price: 5,
                sku: (string) $variant->sku,
            )
        )->execute();

        return $variant;
    }
}
