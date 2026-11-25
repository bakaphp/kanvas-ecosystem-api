<?php

declare(strict_types=1);

namespace Tests\Connectors\NetSuite;

use Closure;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\NetSuite\Actions\PullNetSuiteProductAction;
use Kanvas\Connectors\NetSuite\Actions\PullNetSuiteProductPriceAction;
use Kanvas\Connectors\NetSuite\Enums\ConfigurationEnum;
use Kanvas\Connectors\NetSuite\Enums\CustomFieldEnum;
use Kanvas\Connectors\NetSuite\Importers\NetSuiteProductsImporter;
use Kanvas\Exceptions\ValidationException;
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
use Kanvas\Workflow\Integrations\Imports\Enums\ImportOutcomeEnum;
use Kanvas\Workflow\Integrations\Imports\Enums\IntegrationImportStatusEnum;
use Kanvas\Workflow\Integrations\Imports\Jobs\RunIntegrationImportJob;
use Kanvas\Workflow\Integrations\Imports\Models\IntegrationImport;
use Kanvas\Workflow\Integrations\Models\IntegrationsCompany;
use NetSuite\Classes\InventoryItem;
use NetSuite\Classes\ItemSearch;
use NetSuite\Classes\ItemSearchAdvanced;
use NetSuite\Classes\Location;
use ReflectionProperty;
use Tests\Connectors\NetSuite\Concerns\CreatesNetSuiteIntegration;
use Tests\Connectors\NetSuite\Fakes\FakeNetSuiteClient;
use Tests\Connectors\Traits\HasThrowawayCompany;
use Tests\TestCase;

class NetSuiteProductsImporterTest extends TestCase
{
    use CreatesNetSuiteIntegration;
    use DatabaseTransactions;
    use HasThrowawayCompany;

    protected array $connectionsToTransact = ['mysql', 'ecosystem', 'workflow'];

    private Apps $kanvasApp;
    private Users $kanvasUser;
    private Companies $company;
    private IntegrationsCompany $integrationCompany;
    private FakeNetSuiteClient $client;
    private NetSuiteProductsImporter $importer;
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

        $this->integrationCompany = $this->netSuiteIntegrationFor($this->company);
        $this->client = new FakeNetSuiteClient($this->kanvasApp, $this->company);
        $this->importer = new NetSuiteProductsImporter($this->integrationCompany, $user);
        $this->client->into($this->collaborator('productService'));
        $this->client->into($this->collaborator('productSearch'));
    }

    public function testProductsComeFromTheSavedSearchOncePerItem(): void
    {
        $this->client->soap->searchResults = [
            $this->searchRow(itemId: 'SKU-1', internalId: '11'),
            $this->searchRow(itemId: 'SKU-1', internalId: '11'),
            $this->searchRow(itemId: 'SKU-2', internalId: '12'),
        ];

        $ids = $this->importer->list(['saved_search_id' => '901', 'location_ids' => ['7', '4']]);

        $this->assertSame(['SKU-1', 'SKU-2'], $ids);
        $this->assertInstanceOf(ItemSearchAdvanced::class, $this->client->soap->lastSearch->searchRecord);
        $this->assertSame('901', $this->client->soap->lastSearch->searchRecord->savedSearchId);
    }

    public function testWithoutASavedSearchTheItemsOfTheChosenLocationsAreListedAcrossPages(): void
    {
        $this->client->soap->pages = [
            [$this->item('SKU-1', '11'), $this->item('SKU-2', '12')],
            [$this->item('SKU-3', '13')],
        ];

        $ids = $this->importer->list(['location_ids' => ['7', '4']]);

        $this->assertSame(['SKU-1', 'SKU-2', 'SKU-3'], $ids);
        $this->assertSame([2], $this->client->soap->morePages);

        $search = $this->client->soap->lastSearch->searchRecord;
        $this->assertInstanceOf(ItemSearch::class, $search);
        $this->assertFalse($search->basic->isInactive->searchValue);
        $this->assertSame(['_inventoryItem'], $search->basic->type->searchValue);
        $this->assertSame(
            ['7', '4'],
            array_map(fn (object $ref) => $ref->internalId, $search->inventoryLocationJoin->internalId->searchValue)
        );
    }

    public function testPreparingKeepsTheLocationsAsStringsAndResolvesTheSavedSearch(): void
    {
        $this->company->set(ConfigurationEnum::NET_SUITE_PRODUCTS_SAVED_SEARCH_ID->value, '333');
        $locationImport = $this->collaborator('locationImport');
        $this->client->into($this->collaborator('locations', $locationImport));
        $this->client->soap->searchResults = [$this->location('7', 'Yusen'), $this->location('4', 'Aero')];

        $options = $this->importer->prepare(['location_ids' => [7, '4', '7']]);

        $this->assertSame(['7', '4'], $options['location_ids']);
        $this->assertSame('333', $options['saved_search_id']);
    }

    public function testANewProductIsPulledIntoTheWarehouseOfTheFirstChosenLocation(): void
    {
        $pull = $this->fakePull();
        $this->replace('pullProduct', $pull);

        $outcome = $this->importer->importOne('NEW-' . Str::random(8), ['location_ids' => ['4']]);

        $this->assertSame(ImportOutcomeEnum::CREATED, $outcome);
        $this->assertSame([$this->aero->getId()], $pull->warehouses);
    }

    public function testAnExistingProductIsRefreshedAndGainsARowInEveryChosenWarehouseAtItsCurrentPrice(): void
    {
        $variant = $this->variantStockedIn($this->yusen, price: 9.5);
        $refresh = $this->fakeRefresh();
        $this->replace('priceRefresh', $refresh);

        $outcome = $this->importer->importOne($variant->sku, ['location_ids' => ['7', '4']]);

        $this->assertSame(ImportOutcomeEnum::UPDATED, $outcome);
        $this->assertSame([$variant->sku], $refresh->refreshed);
        $aeroRow = $this->row($variant, $this->aero);
        $this->assertNotNull($aeroRow, 'The product must be carried by every chosen location.');
        $this->assertSame(9.5, (float) $aeroRow->price);
        $this->assertSame(0, (int) $aeroRow->quantity, 'Quantities per location come from the stock import.');
    }

    public function testARefreshThatFindsNothingFailsTheItem(): void
    {
        $variant = $this->variantStockedIn($this->yusen, price: 9.5);
        $this->replace('priceRefresh', $this->fakeRefresh(error: 'Product not found in NetSuite'));

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Product not found in NetSuite');

        $this->importer->importOne($variant->sku, ['location_ids' => ['7']]);
    }

    public function testAnUnmappedLocationFailsTheItem(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('NetSuite location 99 is not mapped to a warehouse.');

        $this->importer->importOne('NEW-' . Str::random(8), ['location_ids' => ['99']]);
    }

    public function testWithoutChosenLocationsNothingIsAddedToOtherWarehouses(): void
    {
        $variant = $this->variantStockedIn($this->yusen, price: 9.5);
        $this->replace('priceRefresh', $this->fakeRefresh());

        $this->importer->importOne($variant->sku, []);

        $this->assertNull($this->row($variant, $this->aero));
    }

    public function testAProductImportFromSeveralLocationsQueuesTheStockImportWhenItEnds(): void
    {
        Queue::fake();
        $this->company->set(ConfigurationEnum::NET_SUITE_STOCK_SAVED_SEARCH_ID->value, '574');

        $summary = $this->importer->completed($this->importRun(['7', '4']));

        $this->assertSame(['stock' => 'queued'], $summary);
        Queue::assertPushed(
            RunIntegrationImportJob::class,
            fn (RunIntegrationImportJob $job) => $job->run->entity === 'stock'
                && $job->run->options['saved_search_id'] === '574'
        );
    }

    public function testWithoutAStockSavedSearchTheProductImportSaysStockWasNotQueued(): void
    {
        Queue::fake();

        $summary = $this->importer->completed($this->importRun(['7', '4']));

        Queue::assertNothingPushed();
        $this->assertSame('skipped', $summary['stock']);
        $this->assertStringContainsString('NET_SUITE_STOCK_SAVED_SEARCH_ID', $summary['reason']);
    }

    public function testAStockImportAlreadyRunningIsSaidToBeSkipped(): void
    {
        Queue::fake();
        $this->company->set(ConfigurationEnum::NET_SUITE_STOCK_SAVED_SEARCH_ID->value, '574');
        $this->importer->completed($this->importRun(['7', '4']));

        $summary = $this->importer->completed($this->importRun(['7', '4']));

        $this->assertSame('skipped', $summary['stock']);
        $this->assertStringContainsString('already in progress', $summary['reason']);
    }

    public function testAProductImportFromOneLocationDoesNotNeedTheStockImport(): void
    {
        Queue::fake();
        $this->company->set(ConfigurationEnum::NET_SUITE_STOCK_SAVED_SEARCH_ID->value, '574');

        $this->assertNull($this->importer->completed($this->importRun(['7'])));
        Queue::assertNothingPushed();
    }

    private function importRun(array $locationIds): IntegrationImport
    {
        return IntegrationImport::create([
            'apps_id' => $this->kanvasApp->getId(),
            'companies_id' => $this->company->getId(),
            'integrations_id' => $this->integrationCompany->integrations_id,
            'integrations_companies_id' => $this->integrationCompany->getId(),
            'users_id' => $this->kanvasUser->getId(),
            'entity' => 'products',
            'status' => IntegrationImportStatusEnum::RUNNING,
            'options' => ['location_ids' => $locationIds],
            'queued_at' => Carbon::now(),
        ]);
    }

    private function collaborator(string $name, ?object $owner = null): object
    {
        $owner ??= $this->importer;

        return new ReflectionProperty($owner, $name)->getValue($owner);
    }

    private function replace(string $name, object $value): void
    {
        new ReflectionProperty($this->importer, $name)->setValue($this->importer, $value);
    }

    private function row(Variants $variant, Warehouses $warehouse): ?VariantsWarehouses
    {
        return VariantsWarehouses::query()
            ->where('products_variants_id', $variant->getId())
            ->where('warehouses_id', $warehouse->getId())
            ->first();
    }

    private function location(string $id, string $name): Location
    {
        $location = new Location();
        $location->internalId = $id;
        $location->name = $name;
        $location->locationType = '_warehouse';
        $location->makeInventoryAvailable = true;

        return $location;
    }

    private function searchRow(?string $itemId, string $internalId): object
    {
        return (object) [
            'basic' => (object) [
                'internalId' => [(object) ['searchValue' => (object) ['internalId' => $internalId]]],
                'itemId' => [(object) ['searchValue' => $itemId]],
            ],
        ];
    }

    private function item(string $itemId, string $internalId): InventoryItem
    {
        $item = new InventoryItem();
        $item->itemId = $itemId;
        $item->internalId = $internalId;

        return $item;
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

    private function fakeRefresh(?string $error = null): PullNetSuiteProductPriceAction
    {
        return new class ($error) extends PullNetSuiteProductPriceAction {
            public array $refreshed = [];

            public function __construct(private ?string $error)
            {
            }

            public function execute(string $barcode): array
            {
                if ($this->error !== null) {
                    return ['item' => $barcode, 'error' => $this->error];
                }

                $this->refreshed[] = $barcode;

                return ['item' => $barcode];
            }
        };
    }
}
