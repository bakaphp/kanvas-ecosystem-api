<?php

declare(strict_types=1);

namespace Tests\Connectors\NetSuite;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\NetSuite\Actions\PullNetSuiteProductStockAction;
use Kanvas\Connectors\NetSuite\Enums\ConfigurationEnum;
use Kanvas\Connectors\NetSuite\Enums\CustomFieldEnum;
use Kanvas\Connectors\NetSuite\Importers\NetSuiteCustomersImporter;
use Kanvas\Connectors\NetSuite\Importers\NetSuiteProductsImporter;
use Kanvas\Connectors\NetSuite\Importers\NetSuiteStockImporter;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Inventory\Support\Setup as InventorySetup;
use Kanvas\Inventory\Warehouses\Models\Warehouses;
use Kanvas\Users\Models\Users;
use Kanvas\Workflow\Integrations\Imports\DataTransferObject\ImportChoice;
use Kanvas\Workflow\Integrations\Imports\Enums\ImportOptionTypeEnum;
use Kanvas\Workflow\Integrations\Imports\Enums\ImportOutcomeEnum;
use Kanvas\Workflow\Integrations\Models\IntegrationsCompany;
use NetSuite\Classes\Customer;
use NetSuite\Classes\CustomerSearchAdvanced;
use NetSuite\Classes\Location;
use NetSuite\Classes\RecordType;
use ReflectionProperty;
use Tests\Connectors\NetSuite\Concerns\CreatesNetSuiteIntegration;
use Tests\Connectors\NetSuite\Fakes\FakeNetSuiteClient;
use Tests\Connectors\Traits\HasThrowawayCompany;
use Tests\TestCase;

class NetSuiteImportersTest extends TestCase
{
    use CreatesNetSuiteIntegration;
    use DatabaseTransactions;
    use HasThrowawayCompany;

    protected array $connectionsToTransact = ['mysql', 'ecosystem', 'crm', 'social', 'workflow'];

    private FakeNetSuiteClient $client;
    private Companies $company;
    private Users $kanvasUser;
    private IntegrationsCompany $integrationCompany;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var Users $user */
        $user = auth()->user();
        $this->kanvasUser = $user;
        $this->company = $this->freshCompany();
        $this->integrationCompany = $this->netSuiteIntegrationFor($this->company);
        $this->client = new FakeNetSuiteClient(app(Apps::class), $this->company);
    }

    public function testTheHandlerOffersCustomersProductsAndStockWithTheirOptions(): void
    {
        $importables = $this->integrationCompany->importables();

        $this->assertSame(['customers', 'products', 'stock'], array_column($importables, 'entity'));
        $this->assertSame(['Customers', 'Productos', 'Stock'], array_column($importables, 'label'));

        $products = $this->integrationCompany->importableFor('products');
        $locations = $products->options[0];
        $this->assertSame('location_ids', $locations->name);
        $this->assertSame(ImportOptionTypeEnum::CHOICES, $locations->type);
        $this->assertSame('Bodegas', $locations->label);
        $this->assertTrue($locations->required && $locations->multiple);
        $this->assertSame('saved_search_id', $products->options[1]->name);
        $this->assertSame('Búsqueda guardada', $products->options[1]->label);

        $stock = $this->integrationCompany->importableFor('stock');
        $this->assertSame(['saved_search_id', 'create_missing'], array_column($stock->options, 'name'));
        $this->assertSame(ImportOptionTypeEnum::BOOLEAN, $stock->options[1]->type);
        $this->assertSame('Crear filas que falten', $stock->options[1]->label);

        $customers = $this->integrationCompany->importableFor('customers');
        $this->assertSame(['saved_search_id'], array_column($customers->options, 'name'));
    }

    public function testCustomersComeFromTheSavedSearch(): void
    {
        $importer = $this->customers();
        $this->client->soap->searchResults = [$this->row(itemId: null, internalId: '501'), $this->row(itemId: null, internalId: '502')];

        $ids = $importer->list(['saved_search_id' => '77']);

        $this->assertSame(['501', '502'], $ids);
        $this->assertInstanceOf(CustomerSearchAdvanced::class, $this->client->soap->lastSearch->searchRecord);
    }

    public function testWithoutASavedSearchEveryActiveCustomerIsListed(): void
    {
        $importer = $this->customers();
        $this->client->soap->searchResults = [$this->customer('601'), $this->customer('602')];

        $ids = $importer->list([]);

        $this->assertSame(['601', '602'], $ids);
        $this->assertFalse($this->client->soap->lastSearch->searchRecord->basic->isInactive->searchValue);
    }

    public function testCustomersBecomeOrganizationsAndARerunSkipsThem(): void
    {
        $importer = $this->customers();
        $id = (string) random_int(100000, 999999);
        $this->client->soap->serve(RecordType::customer, $this->customer($id));

        $first = $importer->importOne($id, []);
        $second = $importer->importOne($id, []);

        $this->assertSame(ImportOutcomeEnum::CREATED, $first);
        $this->assertSame(ImportOutcomeEnum::SKIPPED, $second);
    }

    public function testACustomerImportUsesTheCompanySavedSearchWhenTheInputNamesNone(): void
    {
        $this->company->set(ConfigurationEnum::NET_SUITE_CUSTOMERS_SAVED_SEARCH_ID->value, '88');

        $this->assertSame('88', $this->customers()->prepare([])['saved_search_id']);
        $this->assertSame('99', $this->customers()->prepare(['saved_search_id' => '99'])['saved_search_id']);
    }

    public function testStockReportsTotalsWithoutThePerSkuLists(): void
    {
        $importer = $this->stock();
        $stock = $this->fakeStock([
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
        ]);
        $this->replace($importer, 'stock', $stock);

        $summary = $importer->run(['saved_search_id' => '574', 'create_missing' => true]);

        $this->assertSame([['574', true]], $stock->calls);

        $this->assertSame(2, $summary['processed']);
        $this->assertSame(1, $summary['created']);
        $this->assertSame(3, $summary['not_found_in_kanvas']);
        $this->assertSame([['warehouses_id' => 2, 'location_id' => '6', 'error' => 'Search failed']], $summary['location_errors']);
        $this->assertArrayNotHasKey('locations', $summary);
    }

    public function testCreateMissingDefaultsToFalse(): void
    {
        $importer = $this->stock();
        $stock = $this->fakeStock(['processed' => 0]);
        $this->replace($importer, 'stock', $stock);

        $importer->run(['saved_search_id' => '574']);

        $this->assertSame([['574', false]], $stock->calls);
    }

    public function testStockWithNoMappedWarehouseIsRefused(): void
    {
        $importer = $this->stock();
        $this->replace($importer, 'stock', $this->fakeStock(['error' => 'No warehouse is mapped to a NetSuite location.']));

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('No warehouse is mapped to a NetSuite location.');

        $importer->run(['saved_search_id' => '576']);
    }

    public function testStockWithoutASavedSearchIsRefusedBeforeItIsQueued(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage(ConfigurationEnum::NET_SUITE_STOCK_SAVED_SEARCH_ID->value);

        $this->stock()->prepare([]);
    }

    public function testStockWithoutAMappedWarehouseIsRefusedBeforeItIsQueued(): void
    {
        $this->expectException(ValidationException::class);

        $this->stock()->prepare(['saved_search_id' => '574']);
    }

    public function testStockUsesTheCompanySavedSearchAndKeepsCreateMissing(): void
    {
        new InventorySetup(app(Apps::class), $this->kanvasUser, $this->company)->run();
        Warehouses::getDefault($this->company, app(Apps::class))->set(CustomFieldEnum::NET_SUITE_LOCATION_ID->value, '7');
        $this->company->set(ConfigurationEnum::NET_SUITE_STOCK_SAVED_SEARCH_ID->value, '574');

        $options = $this->stock()->prepare(['create_missing' => true]);

        $this->assertSame('574', $options['saved_search_id']);
        $this->assertTrue($options['create_missing']);
    }

    public function testTheLocationChoicesAreTheActiveLocationsWithTheirSuggestion(): void
    {
        $importer = $this->products();
        $this->client->into($this->collaborator($importer, 'locationService'));
        $this->client->soap->searchResults = [
            $this->location(
                '7',
                'Yusen',
                '_warehouse',
                true
            ),
            $this->location(
                '6',
                'In Transit',
                null,
                true
            ),
        ];

        $choices = $importer->choices('location_ids');

        $this->assertEquals(
            [new ImportChoice('7', 'Yusen', true), new ImportChoice('6', 'In Transit', false)],
            $choices
        );
        $this->assertSame([], $importer->choices('saved_search_id'));
    }

    private function customers(): NetSuiteCustomersImporter
    {
        $importer = new NetSuiteCustomersImporter($this->integrationCompany, $this->kanvasUser);
        $this->client->into($this->collaborator($importer, 'customerSearch'));
        $syncCustomer = $this->collaborator($importer, 'syncCustomer');
        $this->client->into(new ReflectionProperty($syncCustomer, 'service')->getValue($syncCustomer));

        return $importer;
    }

    private function products(): NetSuiteProductsImporter
    {
        return new NetSuiteProductsImporter($this->integrationCompany, $this->kanvasUser);
    }

    private function stock(): NetSuiteStockImporter
    {
        return new NetSuiteStockImporter($this->integrationCompany, $this->kanvasUser);
    }

    private function collaborator(object $importer, string $name): object
    {
        return new ReflectionProperty($importer, $name)->getValue($importer);
    }

    private function replace(object $importer, string $name, object $value): void
    {
        new ReflectionProperty($importer, $name)->setValue($importer, $value);
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

    private function customer(string $internalId): Customer
    {
        $customer = new Customer();
        $customer->internalId = $internalId;
        $customer->entityId = 'C' . $internalId;
        $customer->companyName = 'Import Customer ' . $internalId;
        $customer->email = 'ap+' . Str::random(8) . '@import-customer.test';

        return $customer;
    }

    private function location(
        string $id,
        string $name,
        ?string $type,
        bool $available
    ): Location {
        $location = new Location();
        $location->internalId = $id;
        $location->name = $name;
        $location->locationType = $type;
        $location->makeInventoryAvailable = $available;

        return $location;
    }

    private function fakeStock(array $result): PullNetSuiteProductStockAction
    {
        return new class ($result) extends PullNetSuiteProductStockAction {
            public array $calls = [];

            public function __construct(private array $result)
            {
            }

            public function execute(string|int $savedSearchID = 576, ?array $barcodeFilter = null, bool $createMissing = false): array
            {
                $this->calls[] = [$savedSearchID, $createMissing];

                return $this->result;
            }
        };
    }
}
