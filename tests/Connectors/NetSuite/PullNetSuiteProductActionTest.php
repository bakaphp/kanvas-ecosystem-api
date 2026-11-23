<?php

declare(strict_types=1);

namespace Tests\Connectors\NetSuite;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\NetSuite\Actions\PullNetSuiteProductAction;
use Kanvas\Connectors\NetSuite\Actions\PullNetSuiteProductPriceAction;
use Kanvas\Inventory\Support\Setup as InventorySetup;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Inventory\Warehouses\Actions\CreateWarehouseAction;
use Kanvas\Inventory\Warehouses\DataTransferObject\Warehouses as WarehousesData;
use Kanvas\Inventory\Warehouses\Models\Warehouses;
use Kanvas\Users\Models\Users;
use NetSuite\Classes\InventoryItem;
use NetSuite\Classes\RecordType;
use ReflectionProperty;
use Tests\Connectors\NetSuite\Fakes\FakeNetSuiteClient;
use Tests\Connectors\Traits\HasThrowawayCompany;
use Tests\TestCase;

/**
 * `inventory` is left out of the transacted connections on purpose: CreateProductAction retries its
 * own inventory transaction, which a test-level transaction would demote to a savepoint.
 */
class PullNetSuiteProductActionTest extends TestCase
{
    use DatabaseTransactions;
    use HasThrowawayCompany;

    protected array $connectionsToTransact = ['mysql', 'ecosystem'];

    private Apps $kanvasApp;
    private Users $kanvasUser;
    private Companies $company;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var Users $user */
        $user = auth()->user();
        $this->kanvasUser = $user;
        $this->kanvasApp = app(Apps::class);
        $this->company = $this->freshCompany();
        new InventorySetup($this->kanvasApp, $user, $this->company)->run();
    }

    public function testThePulledProductLandsInTheGivenWarehouseOnly(): void
    {
        $aero = new CreateWarehouseAction(
            new WarehousesData(
                company: $this->company,
                app: $this->kanvasApp,
                user: $this->kanvasUser,
                region: Warehouses::getDefault($this->company, $this->kanvasApp)->region,
                name: 'Aero',
            ),
            $this->kanvasUser
        )->execute();
        $barcode = 'NS-' . Str::random(10);

        $variant = $this->action($barcode)->execute($barcode, $aero);

        $this->assertSame([$aero->getId()], $this->warehouseIds($variant));
    }

    public function testWithoutAWarehouseThePulledProductLandsInTheDefaultOne(): void
    {
        $barcode = 'NS-' . Str::random(10);

        $variant = $this->action($barcode)->execute($barcode);

        $default = Warehouses::getDefault($this->company, $this->kanvasApp);
        $this->assertSame([$default->getId()], $this->warehouseIds($variant));
    }

    private function action(string $barcode): PullNetSuiteProductAction
    {
        $item = new InventoryItem();
        $item->internalId = '9001';
        $item->itemId = $barcode;
        $item->displayName = 'Copic Classic Marker ' . $barcode;

        $client = new FakeNetSuiteClient($this->kanvasApp, $this->company);
        $client->soap->searchResults = [$item];
        $client->soap->serve(RecordType::inventoryItem, $item);

        $action = new PullNetSuiteProductAction($this->kanvasApp, $this->company, $this->kanvasUser);
        $client->into(new ReflectionProperty($action, 'productService')->getValue($action));
        new ReflectionProperty($action, 'priceRefresh')->setValue($action, new class () extends PullNetSuiteProductPriceAction {
            public function __construct()
            {
            }

            public function execute(string $barcode): array
            {
                return ['item' => $barcode];
            }
        });

        return $action;
    }

    /**
     * @return list<int>
     */
    private function warehouseIds(Variants $variant): array
    {
        return $variant->variantWarehouses()->pluck('warehouses_id')->map(fn ($id) => (int) $id)->all();
    }
}
