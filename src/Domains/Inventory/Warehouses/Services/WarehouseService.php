<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Warehouses\Services;

use Baka\Users\Contracts\UserInterface;
use Kanvas\Companies\Models\Companies;
use Kanvas\Companies\Repositories\CompaniesRepository;
use Kanvas\Inventory\Status\Models\Status;
use Kanvas\Inventory\Status\Repositories\StatusRepository;
use Kanvas\Inventory\Variants\Actions\AddToWarehouseAction as AddToWarehouse;
use Kanvas\Inventory\Variants\Actions\UpdateToWarehouseAction;
use Kanvas\Inventory\Variants\DataTransferObject\VariantsWarehouses;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Inventory\Variants\Models\VariantsWarehouses as ModelsVariantsWarehouses;
use Kanvas\Inventory\Warehouses\Models\Warehouses;
use Kanvas\Inventory\Warehouses\Repositories\WarehouseRepository;

class WarehouseService
{
    /**
     * Update data of variant in a warehouse.
     */
    public static function updateWarehouseVariant(Variants $variant, UserInterface $user, array $warehouses): Variants
    {
        $warehousesId = array_column($warehouses, 'warehouse_id');

        $existedWarehouses = $variant->variantWarehouses->keyBy('warehouses_id');
        $existedWarehouses = $existedWarehouses->keys()->all();
        $toDelete = array_diff($existedWarehouses, $warehousesId);

        foreach ($warehouses as $warehouseData) {
            $warehouseModel = WarehouseRepository::getById((int) $warehouseData['warehouse_id'], $variant->product->company);

            if (isset($warehouseData['status'])) {
                $warehouseData['status_id'] = StatusRepository::getById(
                    (int) $warehouseData['status']['id'],
                    $variant->product->company
                )->getId();
            } else {
                $warehouseData['status_id'] = Status::getDefault($variant->product->company)->getId();
            }

            $variantWarehousesDto = VariantsWarehouses::viaRequest($variant, $warehouseModel, $warehouseData);

            (new UpdateToWarehouseAction(
                $variantWarehousesDto
            ))->execute();
        }

        if (! empty($toDelete)) {
            $toDelete = $variant->variantWarehouses
                ->whereIn('warehouses_id', $toDelete)
                ->map(function ($variantWarehouse) use ($user) {
                    WarehouseService::removeVariantWarehouses(
                        $variantWarehouse->variant,
                        $variantWarehouse->warehouse,
                        $user
                    );
                });
        }

        return $variant;
    }

    public static function addToWarehouses(
        Variants $variant,
        Warehouses $warehouse,
        Companies $company,
        array $warehousesInfo
    ): ModelsVariantsWarehouses {
        if (isset($warehousesInfo['status'])) {
            $status = StatusRepository::getById(
                (int) $warehousesInfo['status']['id'],
                $company
            )->getId();
        } else {
            $status = Status::getDefault($company);
        }

        $warehousesInfo['status_id'] = $status ? $status->getId() : null;
        $variantWarehouses = VariantsWarehouses::viaRequest($variant, $warehouse, $warehousesInfo ?? []);

        if ($variant->sku && (! isset($warehousesInfo['sku']) || ! $warehousesInfo['sku'])) {
            $warehousesInfo['sku'] = $variant->sku;
        }
        return (new AddToWarehouse($variant, $warehouse, $variantWarehouses))->execute();
    }

    public static function removeVariantWarehouses(
        Variants $variant,
        Warehouses $warehouse,
        UserInterface $user,
    ): Variants {
        CompaniesRepository::userAssociatedToCompany(
            $variant->company,
            $user
        );
        $variantWarehouse = $variant->variantWarehouses('warehouses_id', $warehouse->getId());
        $variantWarehouse->first()->delete();

        return $variant;
    }
}
