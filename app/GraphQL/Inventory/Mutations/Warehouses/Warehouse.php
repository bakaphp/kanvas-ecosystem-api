<?php
declare(strict_types=1);
namespace App\GraphQL\Inventory\Mutations\Warehouses;

use Kanvas\Inventory\Warehouses\Actions\CreateWarehouseAction;
use Kanvas\Inventory\Warehouses\DataTransferObject\Warehouses as WarehousesDto;
use Kanvas\Inventory\Warehouses\Models\Warehouses;
use Kanvas\Apps\Models\Apps;
use Kanvas\Inventory\Warehouses\Repositories\WarehouseRepository;
use Kanvas\Inventory\Regions\Repositories\RegionRepository;

class Warehouse
{
    /**
     * create
     *
     * @param  mixed $root
     * @param  array $request
     * @return Warehouses
     */
    public function create(mixed $root, array $request): Warehouses
    {
        $request = $request['input'];
        $request['companies_id'] = $request['companies_id'] ?? auth()->user()->default_company;
        $request['apps_id'] = $request['apps_id'] ?? app(Apps::class)->id;
        $request['regions_id'] = RegionRepository::getById($request['regions_id'])->id;
        return (new CreateWarehouseAction(WarehousesDto::fromArray($request)))->execute();
    }

    /**
     * update
     *
     * @param  mixed $root
     * @param  array $request
     * @return Warehouses
     */
    public function update(mixed $root, array $request): Warehouses
    {
        $warehouse = WarehouseRepository::getById($request['id']);
        $request = $request['input'];
        if (key_exists('regions_id', $request)) {
            $request['regions_id'] = RegionRepository::getById($request['regions_id'])->id;
        }
        $warehouse->update($request);
        return $warehouse;
    }

    /**
     * delete
     *
     * @param  mixed $root
     * @param  array $request
     * @return bool
     */
    public function delete(mixed $root, array $request): bool
    {
        $warehouse = WarehouseRepository::getById($request['id']);
        return $warehouse->delete();
    }
}
