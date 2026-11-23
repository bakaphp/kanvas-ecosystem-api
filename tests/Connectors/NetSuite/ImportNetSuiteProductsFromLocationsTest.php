<?php

declare(strict_types=1);

namespace Tests\Connectors\NetSuite;

use Closure;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\NetSuite\Actions\ImportNetSuiteDataAction;
use Kanvas\Connectors\NetSuite\Actions\PullNetSuiteProductAction;
use Kanvas\Connectors\NetSuite\Actions\PullNetSuiteProductPriceAction;
use Kanvas\Connectors\NetSuite\Enums\CustomFieldEnum;
use Kanvas\Connectors\NetSuite\Enums\ImportEntityEnum;
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
use NetSuite\Classes\ItemSearch;
use ReflectionProperty;
use Tests\Connectors\NetSuite\Fakes\FakeNetSuiteClient;
use Tests\Connectors\Traits\HasThrowawayCompany;
use Tests\TestCase;

/**
 * Importing products "from Yusen and Aero": the items those locations carry, landing in the
 * warehouses that mirror them.
 */
class ImportNetSuiteProductsFromLocationsTest extends TestCase
{
    use DatabaseTransactions;
    use HasThrowawayCompany;

    protected array $connectionsToTransact = ['mysql', 'ecosystem', 'inventory'];

    private Apps $kanvasApp;
    private Users $kanvasUser;
    private Companies $company;
    private FakeNetSuiteClient $client;
    private ImportNetSuiteDataAction $action;
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
        $this->aero = new CreateWarehouseAction(
            new WarehousesData(
                company: $this->company,
                app: $this->kanvasApp,
                user: $user,
                region: $this->yusen->region,
                name: 'Aero',
            ),
            $user
        )->execute();
        $this->aero->set(CustomFieldEnum::NET_SUITE_LOCATION_ID->value, '4');

        $this->client = new FakeNetSuiteClient($this->kanvasApp, $this->company);
        $this->action = new ImportNetSuiteDataAction($this->kanvasApp, $this->company, $user);
        $this->client->into($this->collaborator('productService'));
    }

    public function testTheItemsOfTheChosenLocationsAreListedWithoutASavedSearch(): void
    {
        $this->client->soap->searchResults = [];

        $this->action->listIds(ImportEntityEnum::PRODUCTS, null, ['7', '4']);

        $search = $this->client->soap->lastSearch->searchRecord;
        $this->assertInstanceOf(ItemSearch::class, $search);
        $this->assertSame(
            ['7', '4'],
            array_map(fn (object $ref) => $ref->internalId, $search->inventoryLocationJoin->internalId->searchValue)
        );
    }

    public function testANewProductIsPulledIntoTheWarehouseOfTheFirstChosenLocation(): void
    {
        $pull = $this->fakePull();
        $this->replace('pullProduct', $pull);

        $result = $this->action->importRecords(ImportEntityEnum::PRODUCTS, ['NEW-' . Str::random(8)], ['4']);

        $this->assertSame(1, $result['created']);
        $this->assertSame([$this->aero->getId()], $pull->warehouses);
    }

    public function testAnExistingProductGainsARowInEveryChosenWarehouseAtItsCurrentPrice(): void
    {
        $variant = $this->variantStockedIn($this->yusen, price: 9.5);
        $this->replace('priceRefresh', $this->fakeRefresh());

        $result = $this->action->importRecords(ImportEntityEnum::PRODUCTS, [$variant->sku], ['7', '4']);

        $this->assertSame(1, $result['updated']);
        $aeroRow = $this->row($variant, $this->aero);
        $this->assertNotNull($aeroRow, 'The product must be carried by every chosen location.');
        $this->assertSame(9.5, (float) $aeroRow->price);
        $this->assertSame(0, (int) $aeroRow->quantity, 'Quantities per location come from the stock import.');
    }

    public function testWithoutChosenLocationsNothingIsAddedToOtherWarehouses(): void
    {
        $variant = $this->variantStockedIn($this->yusen, price: 9.5);
        $this->replace('priceRefresh', $this->fakeRefresh());

        $this->action->importRecords(ImportEntityEnum::PRODUCTS, [$variant->sku]);

        $this->assertNull($this->row($variant, $this->aero));
    }

    private function collaborator(string $name): object
    {
        return new ReflectionProperty($this->action, $name)->getValue($this->action);
    }

    private function replace(string $name, object $value): void
    {
        new ReflectionProperty($this->action, $name)->setValue($this->action, $value);
    }

    private function row(Variants $variant, Warehouses $warehouse): ?VariantsWarehouses
    {
        return VariantsWarehouses::query()
            ->where('products_variants_id', $variant->getId())
            ->where('warehouses_id', $warehouse->getId())
            ->first();
    }

    private function variantStockedIn(Warehouses $warehouse, float $price): Variants
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

        new AddToWarehouseAction(
            $variant,
            $warehouse,
            new VariantsWarehousesData(
                variant: $variant,
                warehouse: $warehouse,
                quantity: 12,
                price: $price,
                sku: (string) $variant->sku,
            )
        )->execute();

        return $variant;
    }

    /**
     * Stands in for the pull: the variant appears in the warehouse it was pulled into, at no price
     * until the refresh.
     */
    private function fakePull(): PullNetSuiteProductAction
    {
        return new class (fn (Warehouses $warehouse) => $this->variantStockedIn($warehouse, price: 0)) extends PullNetSuiteProductAction {
            public array $warehouses = [];

            public function __construct(private Closure $create)
            {
            }

            public function execute(string $barcode, ?Warehouses $warehouse = null): Variants
            {
                $this->warehouses[] = $warehouse?->getId();

                return ($this->create)($warehouse);
            }
        };
    }

    private function fakeRefresh(): PullNetSuiteProductPriceAction
    {
        return new class () extends PullNetSuiteProductPriceAction {
            public function __construct()
            {
            }

            public function execute(string $barcode): array
            {
                return ['item' => $barcode];
            }
        };
    }
}
