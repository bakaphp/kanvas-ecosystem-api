<?php

declare(strict_types=1);

namespace Tests\Connectors\NetSuite;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\NetSuite\Actions\ImportNetSuiteDataAction;
use Kanvas\Connectors\NetSuite\Actions\PullNetSuiteProductAction;
use Kanvas\Connectors\NetSuite\Actions\PullNetSuiteProductPriceAction;
use Kanvas\Connectors\NetSuite\Actions\PullNetSuiteProductStockAction;
use Kanvas\Connectors\NetSuite\Enums\ImportEntityEnum;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Inventory\Products\Factories\ProductFactory;
use Kanvas\Inventory\Support\Setup as InventorySetup;
use Kanvas\Inventory\Variants\Factories\VariantFactory;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Inventory\Warehouses\Models\Warehouses;
use Kanvas\Users\Models\Users;
use NetSuite\Classes\Customer;
use NetSuite\Classes\CustomerSearchAdvanced;
use NetSuite\Classes\InventoryItem;
use NetSuite\Classes\ItemSearch;
use NetSuite\Classes\ItemSearchAdvanced;
use NetSuite\Classes\RecordType;
use ReflectionProperty;
use Tests\Connectors\NetSuite\Fakes\FakeNetSuiteClient;
use Tests\TestCase;

class ImportNetSuiteDataActionTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'ecosystem', 'crm', 'social', 'inventory'];

    private FakeNetSuiteClient $client;
    private Companies $company;
    private Users $kanvasUser;
    private ImportNetSuiteDataAction $action;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var Users $user */
        $user = auth()->user();
        $this->kanvasUser = $user;
        $this->company = $user->getCurrentCompany();
        $this->client = new FakeNetSuiteClient(app(Apps::class), $this->company);
        $this->action = new ImportNetSuiteDataAction(app(Apps::class), $this->company, $user);

        foreach (['productSearch', 'productService', 'customerSearch'] as $service) {
            $this->client->into($this->collaborator($service));
        }

        $syncCustomer = $this->collaborator('syncCustomer');
        $this->client->into(new ReflectionProperty($syncCustomer, 'service')->getValue($syncCustomer));
    }

    public function testProductsComeFromTheSavedSearchOncePerItem(): void
    {
        // A saved search joined to locations answers one row per item and location.
        $this->client->soap->searchResults = [
            $this->row(itemId: 'SKU-1', internalId: '11'),
            $this->row(itemId: 'SKU-1', internalId: '11'),
            $this->row(itemId: 'SKU-2', internalId: '12'),
        ];

        $ids = $this->action->listIds(ImportEntityEnum::PRODUCTS, '901');

        $this->assertSame(['SKU-1', 'SKU-2'], $ids);
        $this->assertInstanceOf(ItemSearchAdvanced::class, $this->client->soap->lastSearch->searchRecord);
        $this->assertSame('901', $this->client->soap->lastSearch->searchRecord->savedSearchId);
    }

    public function testWithoutASavedSearchEveryActiveInventoryItemIsListedAcrossPages(): void
    {
        $this->client->soap->pages = [
            [$this->item('SKU-1', '11'), $this->item('SKU-2', '12')],
            [$this->item('SKU-3', '13')],
        ];

        $ids = $this->action->listIds(ImportEntityEnum::PRODUCTS, null);

        $this->assertSame(['SKU-1', 'SKU-2', 'SKU-3'], $ids);
        $this->assertSame([2], $this->client->soap->morePages);

        $search = $this->client->soap->lastSearch->searchRecord;
        $this->assertInstanceOf(ItemSearch::class, $search);
        $this->assertFalse($search->basic->isInactive->searchValue);
        $this->assertSame(['_inventoryItem'], $search->basic->type->searchValue);
    }

    public function testCustomersComeFromTheSavedSearch(): void
    {
        $this->client->soap->searchResults = [$this->row(itemId: null, internalId: '501'), $this->row(itemId: null, internalId: '502')];

        $ids = $this->action->listIds(ImportEntityEnum::CUSTOMERS, '77');

        $this->assertSame(['501', '502'], $ids);
        $this->assertInstanceOf(CustomerSearchAdvanced::class, $this->client->soap->lastSearch->searchRecord);
    }

    public function testWithoutASavedSearchEveryActiveCustomerIsListed(): void
    {
        $this->client->soap->searchResults = [$this->customer('601'), $this->customer('602')];

        $ids = $this->action->listIds(ImportEntityEnum::CUSTOMERS, null);

        $this->assertSame(['601', '602'], $ids);
        $this->assertFalse($this->client->soap->lastSearch->searchRecord->basic->isInactive->searchValue);
    }

    public function testNewProductsArePulledAndExistingOnesRefreshed(): void
    {
        new InventorySetup(app(Apps::class), $this->kanvasUser, $this->company)->run();
        $existing = $this->variant();

        $pull = $this->fakePull();
        $refresh = $this->fakeRefresh();
        $this->replace('pullProduct', $pull);
        $this->replace('priceRefresh', $refresh);

        $new = 'NEW-' . Str::random(8);
        $result = $this->action->importRecords(ImportEntityEnum::PRODUCTS, [$new, $existing->sku]);

        $this->assertSame([$new], $pull->pulled);
        $this->assertSame([$existing->sku], $refresh->refreshed);
        $this->assertSame(1, $result['created']);
        $this->assertSame(1, $result['updated']);
        $this->assertSame(0, $result['failed']);
    }

    public function testOneProductFailingDoesNotStopTheRest(): void
    {
        $pull = $this->fakePull(failOn: 'BAD-1');
        $this->replace('pullProduct', $pull);

        $result = $this->action->importRecords(ImportEntityEnum::PRODUCTS, ['BAD-1', 'OK-' . Str::random(8)]);

        $this->assertSame(1, $result['created']);
        $this->assertSame(1, $result['failed']);
        $this->assertSame([['id' => 'BAD-1', 'error' => 'BAD-1 does not exist in NetSuite']], $result['errors']);
    }

    public function testARefreshThatFindsNothingCountsAsAFailure(): void
    {
        new InventorySetup(app(Apps::class), $this->kanvasUser, $this->company)->run();
        $existing = $this->variant();
        $this->replace('priceRefresh', $this->fakeRefresh(error: 'Product not found in NetSuite'));

        $result = $this->action->importRecords(ImportEntityEnum::PRODUCTS, [$existing->sku]);

        $this->assertSame(0, $result['updated']);
        $this->assertSame([['id' => $existing->sku, 'error' => 'Product not found in NetSuite']], $result['errors']);
    }

    public function testCustomersBecomeOrganizationsAndARerunSkipsThem(): void
    {
        $id = (string) random_int(100000, 999999);
        $this->client->soap->serve(RecordType::customer, $this->customer($id));

        $first = $this->action->importRecords(ImportEntityEnum::CUSTOMERS, [$id]);
        $second = $this->action->importRecords(ImportEntityEnum::CUSTOMERS, [$id]);

        $this->assertSame(1, $first['created']);
        $this->assertSame(0, $second['created']);
        $this->assertSame(1, $second['skipped']);
    }

    public function testStockReportsTotalsWithoutThePerSkuLists(): void
    {
        $this->replace('stock', $this->fakeStock([
            'searchId' => '576',
            'processed' => 2,
            'created' => 1,
            'not_found_in_kanvas' => 3,
            'not_found_in_netsuite' => 0,
            'not_in_warehouse' => 0,
            'update_failed' => 0,
            'locations' => [
                ['warehouses_id' => 1, 'location_id' => '5', 'processed' => ['A', 'B'], 'not_found_in_kanvas' => ['C', 'D', 'E']],
                ['warehouses_id' => 2, 'location_id' => '6', 'error' => 'Search failed'],
            ],
        ]));

        $summary = $this->action->importStock('576', createMissing: true);

        $this->assertSame(2, $summary['processed']);
        $this->assertSame(1, $summary['created']);
        $this->assertSame(3, $summary['not_found_in_kanvas']);
        $this->assertSame([['warehouses_id' => 2, 'location_id' => '6', 'error' => 'Search failed']], $summary['location_errors']);
        $this->assertArrayNotHasKey('locations', $summary);
    }

    public function testStockWithNoMappedWarehouseIsRefused(): void
    {
        $this->replace('stock', $this->fakeStock(['error' => 'No warehouse is mapped to a NetSuite location.']));

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('No warehouse is mapped to a NetSuite location.');

        $this->action->importStock('576', createMissing: false);
    }

    private function collaborator(string $name): object
    {
        return new ReflectionProperty($this->action, $name)->getValue($this->action);
    }

    private function replace(string $name, object $value): void
    {
        new ReflectionProperty($this->action, $name)->setValue($this->action, $value);
    }

    private function row(?string $itemId, string $internalId): object
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

    private function customer(string $internalId): Customer
    {
        $customer = new Customer();
        $customer->internalId = $internalId;
        $customer->entityId = 'C' . $internalId;
        $customer->companyName = 'Import Customer ' . $internalId;
        $customer->email = 'ap+' . Str::random(8) . '@import-customer.test';

        return $customer;
    }

    private function variant(): Variants
    {
        $app = app(Apps::class);
        $product = ProductFactory::new()
            ->withAppId($app->getId())
            ->withCompanyId($this->company->getId())
            ->withUserId($this->kanvasUser->getId())
            ->create();

        return VariantFactory::new()
            ->withProductId($product->getId())
            ->withAppId($app->getId())
            ->withCompanyId($this->company->getId())
            ->withUserId($this->kanvasUser->getId())
            ->create();
    }

    private function fakePull(?string $failOn = null): PullNetSuiteProductAction
    {
        return new class ($failOn) extends PullNetSuiteProductAction {
            public array $pulled = [];

            public function __construct(private ?string $failOn)
            {
            }

            public function execute(string $barcode, ?Warehouses $warehouse = null): Variants
            {
                if ($barcode === $this->failOn) {
                    throw new ValidationException($barcode . ' does not exist in NetSuite');
                }

                $this->pulled[] = $barcode;

                return new Variants();
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

    private function fakeStock(array $result): PullNetSuiteProductStockAction
    {
        return new class ($result) extends PullNetSuiteProductStockAction {
            public function __construct(private array $result)
            {
            }

            public function execute(string|int $savedSearchID = 576, ?array $barcodeFilter = null, bool $createMissing = false): array
            {
                return $this->result;
            }
        };
    }
}
