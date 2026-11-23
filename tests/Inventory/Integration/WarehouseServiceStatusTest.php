<?php

declare(strict_types=1);

namespace Tests\Inventory\Integration;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Status\Actions\CreateStatusAction;
use Kanvas\Inventory\Status\DataTransferObject\Status as StatusDto;
use Kanvas\Inventory\Status\Models\Status;
use Kanvas\Inventory\Support\Setup as InventorySetup;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Inventory\Warehouses\Models\Warehouses;
use Kanvas\Inventory\Warehouses\Services\WarehouseService;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

final class WarehouseServiceStatusTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'inventory'];

    private Apps $kanvasApp;
    private Users $kanvasUser;
    private Companies $company;
    private Warehouses $warehouse;
    private Variants $variant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->kanvasApp = app(Apps::class);
        $this->kanvasUser = static::$cachedUser;
        $this->company = $this->kanvasUser->getCurrentCompany();

        new InventorySetup($this->kanvasApp, $this->kanvasUser, $this->company)->run();

        $product = Products::factory()
            ->withAppId($this->kanvasApp->getId())
            ->withCompanyId($this->company->getId())
            ->create();
        $this->variant = Variants::factory()
            ->withAppId($this->kanvasApp->getId())
            ->withProductId($product->getId())
            ->create();
        $this->warehouse = Warehouses::fromApp($this->kanvasApp)
            ->fromCompany($this->company)
            ->firstOrFail();
    }

    public function testAnExplicitStatusIsStoredOnTheWarehouseRow(): void
    {
        $status = new CreateStatusAction(
            new StatusDto(
                $this->kanvasApp,
                $this->company,
                $this->kanvasUser,
                'Backorder ' . uniqid()
            ),
            $this->kanvasUser
        )->execute();

        $variantWarehouse = WarehouseService::addToWarehouses(
            $this->variant,
            $this->warehouse,
            $this->company,
            [
                'quantity' => 7,
                'price' => 10.5,
                'status' => ['id' => $status->getId()],
            ]
        );

        $this->assertSame($status->getId(), (int) $variantWarehouse->status_id);
        $this->assertSame(7.0, (float) $variantWarehouse->quantity);
    }

    public function testNoStatusFallsBackToTheCompanyDefault(): void
    {
        $default = Status::getDefault($this->company);
        $this->assertNotNull($default);

        $variantWarehouse = WarehouseService::addToWarehouses(
            $this->variant,
            $this->warehouse,
            $this->company,
            ['quantity' => 3]
        );

        $this->assertSame($default->getId(), (int) $variantWarehouse->status_id);
    }

    public function testUpdatingStockForACompanyWithNoDefaultStatusLeavesTheStatusEmpty(): void
    {
        Status::where('companies_id', $this->company->getId())->update(['is_default' => 0]);

        WarehouseService::updateWarehouseVariant(
            $this->variant,
            $this->kanvasUser,
            [['id' => $this->warehouse->getId(), 'quantity' => 4]]
        );

        $variantWarehouse = $this->variant->variantWarehouses()->firstOrFail();
        $this->assertNull($variantWarehouse->status_id);
        $this->assertSame(4.0, (float) $variantWarehouse->quantity);
    }
}
