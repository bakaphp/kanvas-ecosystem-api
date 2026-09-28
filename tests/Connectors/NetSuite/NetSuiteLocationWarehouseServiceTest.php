<?php

declare(strict_types=1);

namespace Tests\Connectors\NetSuite;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\NetSuite\Enums\ConfigurationEnum;
use Kanvas\Connectors\NetSuite\Enums\CustomFieldEnum;
use Kanvas\Connectors\NetSuite\Services\NetSuiteLocationWarehouseService;
use Kanvas\Inventory\Regions\Models\Regions;
use Kanvas\Inventory\Support\Setup as InventorySetup;
use Kanvas\Inventory\Warehouses\Actions\CreateWarehouseAction;
use Kanvas\Inventory\Warehouses\DataTransferObject\Warehouses as WarehousesDto;
use Kanvas\Inventory\Warehouses\Models\Warehouses;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

/**
 * The mapping has to be tenant-shaped, not Copic-shaped: one warehouse today, several later, with
 * no code change in between.
 */
class NetSuiteLocationWarehouseServiceTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'ecosystem', 'inventory'];

    private Apps $kanvasApp;
    private Users $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->kanvasApp = app(Apps::class);
        /** @var Users $user */
        $user = auth()->user();
        $this->user = $user;

        new InventorySetup($this->kanvasApp, $user, $user->getCurrentCompany())->run();
    }

    protected function tearDown(): void
    {
        $this->company()->set(ConfigurationEnum::NET_SUITE_DEFAULT_WAREHOUSE->value, '');

        parent::tearDown();
    }

    public function testMapsEveryWarehouseThatCarriesALocation(): void
    {
        $aero = $this->createWarehouse('NsAero');
        $yusen = $this->createWarehouse('NsYusen');

        $aero->set(CustomFieldEnum::NET_SUITE_LOCATION_ID->value, '4');
        $yusen->set(CustomFieldEnum::NET_SUITE_LOCATION_ID->value, '7');

        $map = $this->service()->map();

        $this->assertSame('4', $map[$aero->getId()]);
        $this->assertSame('7', $map[$yusen->getId()]);
    }

    public function testWarehousesWithoutALocationAreLeftOutRatherThanGuessed(): void
    {
        $mapped = $this->createWarehouse('NsMapped');
        $unmapped = $this->createWarehouse('NsUnmapped');

        $mapped->set(CustomFieldEnum::NET_SUITE_LOCATION_ID->value, '7');
        // The company default must NOT leak onto the unmapped warehouse — that would point a
        // second warehouse at the first one's location and overwrite its stock.
        $this->company()->set(ConfigurationEnum::NET_SUITE_DEFAULT_WAREHOUSE->value, '4');

        $map = $this->service()->map();

        $this->assertSame('7', $map[$mapped->getId()]);
        $this->assertArrayNotHasKey($unmapped->getId(), $map);
    }

    public function testFallsBackToTheCompanyDefaultWhenNothingIsMapped(): void
    {
        // Existing single-warehouse tenants have no per-warehouse mapping and must keep working.
        $this->company()->set(ConfigurationEnum::NET_SUITE_DEFAULT_WAREHOUSE->value, '4');

        $map = $this->service()->map();

        $this->assertCount(1, $map);
        $this->assertSame('4', reset($map));
    }

    public function testReturnsNothingWhenThereIsNoMappingAtAll(): void
    {
        $this->company()->set(ConfigurationEnum::NET_SUITE_DEFAULT_WAREHOUSE->value, '');

        $this->assertSame([], $this->service()->map());
    }

    private function service(): NetSuiteLocationWarehouseService
    {
        return new NetSuiteLocationWarehouseService($this->kanvasApp, $this->company());
    }

    private function company(): object
    {
        return $this->user->getCurrentCompany();
    }

    private function createWarehouse(string $name): Warehouses
    {
        $company = $this->company();
        /** @var Regions $region */
        $region = Regions::fromApp($this->kanvasApp)->fromCompany($company)->firstOrFail();

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
