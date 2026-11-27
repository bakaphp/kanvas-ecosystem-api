<?php

declare(strict_types=1);

namespace Tests\Connectors\NetSuite;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\NetSuite\Actions\ImportNetSuiteLocationsAction;
use Kanvas\Connectors\NetSuite\Enums\ConfigurationEnum;
use Kanvas\Connectors\NetSuite\Enums\CustomFieldEnum;
use Kanvas\Connectors\NetSuite\Services\NetSuiteLocationService;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Inventory\Support\Setup as InventorySetup;
use Kanvas\Inventory\Warehouses\Models\Warehouses;
use Kanvas\Users\Models\Users;
use NetSuite\Classes\Location;
use NetSuite\Classes\LocationSearch;
use ReflectionProperty;
use Tests\Connectors\NetSuite\Fakes\FakeNetSuiteClient;
use Tests\Connectors\Traits\HasThrowawayCompany;
use Tests\TestCase;

class ImportNetSuiteLocationsActionTest extends TestCase
{
    use DatabaseTransactions;
    use HasThrowawayCompany;

    protected array $connectionsToTransact = ['mysql', 'ecosystem', 'inventory'];

    private Apps $kanvasApp;
    private Users $kanvasUser;
    private Companies $company;
    private FakeNetSuiteClient $client;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var Users $user */
        $user = auth()->user();
        $this->kanvasUser = $user;
        $this->kanvasApp = app(Apps::class);
        $this->company = $this->freshCompany();
        new InventorySetup($this->kanvasApp, $user, $this->company)->run();

        $this->client = new FakeNetSuiteClient($this->kanvasApp, $this->company);
        $this->client->soap->searchResults = [
            $this->location(
                id: '1',
                name: 'Pleasanton',
                type: null,
                available: false
            ),
            $this->location(
                id: '4',
                name: 'Aero',
                type: '_warehouse',
                available: true
            ),
            $this->location(
                id: '6',
                name: 'In Transit',
                type: null,
                available: true
            ),
            $this->location(
                id: '7',
                name: 'Yusen',
                type: '_warehouse',
                available: true
            ),
        ];
    }

    public function testLocationsAreListedWithTheOnesThatLookLikeSellableWarehousesSuggested(): void
    {
        $locations = $this->client->into(new NetSuiteLocationService($this->kanvasApp, $this->company))
            ->active();

        $this->assertInstanceOf(LocationSearch::class, $this->client->soap->lastSearch->searchRecord);
        $this->assertFalse($this->client->soap->lastSearch->searchRecord->basic->isInactive->searchValue);
        $this->assertSame(
            [
                ['id' => '1', 'name' => 'Pleasanton', 'type' => null, 'inventory_available' => false, 'suggested' => false],
                ['id' => '4', 'name' => 'Aero', 'type' => 'warehouse', 'inventory_available' => true, 'suggested' => true],
                ['id' => '6', 'name' => 'In Transit', 'type' => null, 'inventory_available' => true, 'suggested' => false],
                ['id' => '7', 'name' => 'Yusen', 'type' => 'warehouse', 'inventory_available' => true, 'suggested' => true],
            ],
            $locations
        );
    }

    public function testTheDefaultLocationIsMappedOntoTheDefaultWarehouse(): void
    {
        $default = $this->defaultWarehouse();
        $before = $this->warehouseCount();

        $warehouses = $this->action()->execute('7', []);

        $this->assertCount(1, $warehouses);
        $this->assertSame($default->getId(), $warehouses[0]->getId());
        $this->assertSame('7', (string) $default->get(CustomFieldEnum::NET_SUITE_LOCATION_ID->value));
        $this->assertSame('7', (string) $this->company->get(ConfigurationEnum::NET_SUITE_DEFAULT_WAREHOUSE->value));
        $this->assertSame($before, $this->warehouseCount(), 'Products already sit in the default warehouse; a parallel one would strand them.');
    }

    public function testEachFurtherLocationBecomesItsOwnWarehouse(): void
    {
        $warehouses = $this->action()->execute('7', ['4']);

        $this->assertCount(2, $warehouses);
        $aero = $warehouses[1];
        $this->assertSame('Aero', $aero->name);
        $this->assertFalse((bool) $aero->is_default);
        $this->assertSame('4', (string) $aero->get(CustomFieldEnum::NET_SUITE_LOCATION_ID->value));
        $this->assertSame($this->defaultWarehouse()->regions_id, $aero->regions_id);
    }

    public function testARerunReusesTheWarehousesItMapped(): void
    {
        $first = $this->action()->execute('7', ['4']);
        $count = $this->warehouseCount();

        $second = $this->action()->execute('7', ['4', '7']);

        $this->assertSame($count, $this->warehouseCount());
        $this->assertSame(
            array_map(fn (Warehouses $w) => $w->getId(), $first),
            array_map(fn (Warehouses $w) => $w->getId(), $second)
        );
    }

    public function testMovingTheDefaultToALocationAnotherWarehouseMirrorsIsRefused(): void
    {
        $this->action()->execute('7', ['4']);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('NetSuite location 4 is already mapped to warehouse Aero.');

        $this->action()->execute('4', []);
    }

    public function testALocationThatIsNotActiveInNetSuiteIsRefused(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('9 is not an active NetSuite location.');

        $this->action()->execute('7', ['9']);
    }

    public function testChoosingLocationsOnAnUnmappedCompanyMakesTheFirstChoiceTheDefault(): void
    {
        $warehouses = $this->action()->ensureMapped(['4', '7']);

        $this->assertSame($this->defaultWarehouse()->getId(), $warehouses[0]->getId());
        $this->assertSame('4', (string) $warehouses[0]->get(CustomFieldEnum::NET_SUITE_LOCATION_ID->value));
        $this->assertSame('Yusen', $warehouses[1]->name);
    }

    public function testChoosingLocationsKeepsTheCurrentDefaultAndReturnsOnlyTheChosenOnes(): void
    {
        $this->action()->execute('7', []);

        $warehouses = $this->action()->ensureMapped(['4']);

        $this->assertCount(1, $warehouses);
        $this->assertSame('Aero', $warehouses[0]->name);
        $this->assertSame(
            '7',
            (string) $this->defaultWarehouse()->get(CustomFieldEnum::NET_SUITE_LOCATION_ID->value)
        );
    }

    public function testChoosingLocationsReturnsTheirWarehousesInTheOrderChosen(): void
    {
        [$default, $aero] = $this->action()->execute('7', ['4']);

        $warehouses = $this->action()->ensureMapped(['4', '7']);

        $this->assertSame([$aero->getId(), $default->getId()], array_map(fn (Warehouses $w) => $w->getId(), $warehouses));
    }

    public function testACompanyWithoutADefaultWarehouseIsRefused(): void
    {
        $company = $this->freshCompany();
        $action = new ImportNetSuiteLocationsAction($this->kanvasApp, $company, $this->kanvasUser);
        $this->client->into(new ReflectionProperty($action, 'locations')->getValue($action));

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('This company has no default warehouse. Set up its inventory first.');

        $action->execute('7', []);
    }

    private function action(): ImportNetSuiteLocationsAction
    {
        $action = new ImportNetSuiteLocationsAction($this->kanvasApp, $this->company, $this->kanvasUser);
        $this->client->into(new ReflectionProperty($action, 'locations')->getValue($action));

        return $action;
    }

    private function defaultWarehouse(): Warehouses
    {
        return Warehouses::getDefault($this->company, $this->kanvasApp);
    }

    private function warehouseCount(): int
    {
        return Warehouses::query()
            ->fromApp($this->kanvasApp)
            ->fromCompany($this->company)
            ->notDeleted()
            ->count();
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
}
