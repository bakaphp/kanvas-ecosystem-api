<?php
declare(strict_types=1);
namespace Kanvas\Inventory\Warehouses\Actions;

use Kanvas\Inventory\Warehouses\Models\Warehouses;
use Kanvas\Inventory\Warehouses\DataTransferObject\Warehouses as WarehousesDto;
use Illuminate\Support\Str;

class CreateWarehouseAction
{
    /**
     * __construct
     * @param  WarehousesDto $dto
     * @return void
     */
    public function __construct(
        private WarehousesDto $warehouseDto
    ) {
    }

    /**
     * execute
     *
     * @return Warehouses
     */
    public function execute(): Warehouses
    {
        return Warehouses::create([
            'companies_id' => $this->warehouseDto->companies_id,
            'apps_id' => $this->warehouseDto->apps_id,
            'regions_id' => $this->warehouseDto->regions_id,
            'uuid' => Str::uuid(),
            'name' => $this->warehouseDto->name,
            'location' => $this->warehouseDto->location,
            'is_default' => $this->warehouseDto->is_default,
            'is_published' => $this->warehouseDto->is_published,
        ]);
    }
}
