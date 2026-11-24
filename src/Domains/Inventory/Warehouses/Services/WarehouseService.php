<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Warehouses\Services;

use Baka\Contracts\CompanyInterface;
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
        $warehousesId = array_column($warehouses, 'id');

        $existedWarehouses = $variant->variantWarehouses->keyBy('warehouses_id');
        $existedWarehouses = $existedWarehouses->keys()->all();
        $toDelete = array_diff($existedWarehouses, $warehousesId);

        foreach ($warehouses as $warehouseData) {
            $warehouseModel = WarehouseRepository::getById((int) $warehouseData['id'], $variant->product->company);
            $variantWarehouseData = $variant->variantWarehouses()->where('warehouses_id', $warehouseModel->getId())->withTrashed()->first();

            if ($variantWarehouseData && $variantWarehouseData->trashed()) {
                $variantWarehouseData->restore();
            }

            $warehouseData['status_id'] = self::resolveStatusId($warehouseData, $variant->product->company);

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
        $warehousesInfo['status_id'] = self::resolveStatusId($warehousesInfo, $company);
        $variantWarehouses = VariantsWarehouses::viaRequest($variant, $warehouse, $warehousesInfo);

        return new AddToWarehouse($variant, $warehouse, $variantWarehouses)->execute();
    }

    private static function resolveStatusId(array $warehouseInfo, CompanyInterface $company): ?int
    {
        $status = isset($warehouseInfo['status'])
            ? StatusRepository::getById((int) $warehouseInfo['status']['id'], $company)
            : Status::getDefault($company);

        return $status?->getId();
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
        $variantWarehouse = $variant->variantWarehouses()->where('warehouses_id', $warehouse->getId());
        $variantWarehouse->first()->delete();

        return $variant;
    }
}
