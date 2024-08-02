<?php

declare(strict_types=1);

namespace App\GraphQL\Inventory\Mutations\Warehouses;

use Kanvas\Companies\Repositories\CompaniesRepository;
use Kanvas\Inventory\Regions\Repositories\RegionRepository;
use Kanvas\Inventory\Variants\Actions\DeleteWarehouseAction;
use Kanvas\Inventory\Warehouses\Actions\CreateWarehouseAction;
use Kanvas\Inventory\Warehouses\DataTransferObject\Warehouses as WarehousesDto;
use Kanvas\Inventory\Warehouses\Models\Warehouses;
use Kanvas\Inventory\Warehouses\Repositories\WarehouseRepository;

class Warehouse
{
    /**
     * create.
     */
    public function create(mixed $root, array $request): Warehouses
    {
        $request = $request['input'];

        $user = auth()->user();
        $company = $user->getCurrentCompany();
        if (! $user->isAppOwner()) {
            unset($request['companies_id']);
        }

        return (new CreateWarehouseAction(
            WarehousesDto::viaRequest($request, $user, $company),
            $user
        ))->execute();
    }

    /**
     * update.
     */
    public function update(mixed $root, array $request): Warehouses
    {
        $warehouse = WarehouseRepository::getById((int) $request['id'], auth()->user()->getCurrentCompany());
        $request = $request['input'];
        if (key_exists('regions_id', $request)) {
            $request['regions_id'] = RegionRepository::getById(
                (int) $request['regions_id'],
                auth()->user()->getCurrentCompany()
            )->getKey();
        }
        $warehouse->update($request);

        return $warehouse;
    }

    /**
     * delete.
     */
    public function delete(mixed $root, array $request): bool
    {
        $warehouse = WarehouseRepository::getById((int) $request['id'], auth()->user()->getCurrentCompany());

        CompaniesRepository::userAssociatedToCompany(
            $warehouse->company,
            auth()->user()
        );

        return $warehouse->delete();
    }
}
