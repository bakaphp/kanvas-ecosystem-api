<?php

declare(strict_types=1);

namespace App\GraphQL\Inventory\Builders\Variants;

use GraphQL\Type\Definition\ResolveInfo;
use Illuminate\Database\Eloquent\Builder;
use Kanvas\Inventory\Status\Models\Status;
use Kanvas\Inventory\Variants\Models\Variants as ModelsVariants;
use Kanvas\Inventory\Variants\Models\VariantsWarehouses;
use Kanvas\Inventory\Warehouses\Models\Warehouses;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

class VariantWarehouseBuilder
{
    public function allVariantsInWarehouse(
        mixed $root,
        array $args,
        GraphQLContext $context,
        ResolveInfo $resolveInfo
    ): Builder {
        $warehouseId = $args['warehouse_id'];

        $warehouse = Warehouses::fromApp()
        ->where('id', $warehouseId)
        ->unless(auth()->user()->isAppOwner(), function (Builder $warehouse) {
            $warehouse->fromCompany(auth()->user()->getCurrentCompany());
        });

        $variants = new ModelsVariants();
        $variantWarehouse = new VariantsWarehouses();

        //set index
        ModelsVariants::setSearchIndex((int) $warehouse->companies_id);

        /**
         * @var Builder
         */
        return ModelsVariants::join($variantWarehouse->getTable(), $variantWarehouse->getTable() . '.products_variants_id', '=', $variants->getTable() . '.id')
            ->where($variantWarehouse->getTable() . '.warehouses_id', $warehouse->getId())
            ->where($variantWarehouse->getTable() . '.is_deleted', 0)
            ->where($variantWarehouse->getTable() . '.is_published', 1);
    }

    public function getVariantsByStatus(
        mixed $root,
        array $args,
        GraphQLContext $context,
        ResolveInfo $resolveInfo
    ): Builder {
        $warehouseId = $args['warehouse_id'];
        $statusId = $args['status_id'];
        $statusIdArray = [];

        $warehouse = Warehouses::fromApp()
        ->where('id', $warehouseId)
        ->unless(auth()->user()->isAppOwner(), function (Builder $warehouse) {
            $warehouse->fromCompany(auth()->user()->getCurrentCompany());
        });

        foreach ($statusId as $ids) {
            $status = Status::fromApp()
            ->where('id', $ids)
            ->unless(auth()->user()->isAppOwner(), function (Builder $status) {
                $status->fromCompany(auth()->user()->getCurrentCompany());
            });
            $statusIdArray[] = $status->firstOrFail()->getId();
        }
        $warehouse = $warehouse->firstOrFail();

        $variants = new ModelsVariants();
        $variantWarehouse = new VariantsWarehouses();

        //set index
        ModelsVariants::setSearchIndex((int) $warehouse->companies_id);

        /**
         * @var Builder
         */
        return ModelsVariants::join($variantWarehouse->getTable(), $variantWarehouse->getTable() . '.products_variants_id', '=', $variants->getTable() . '.id')
            ->where($variantWarehouse->getTable() . '.warehouses_id', '=', $warehouse->getId())
            ->whereIn($variantWarehouse->getTable() . '.status_id', $statusIdArray)
            ->where($variantWarehouse->getTable() . '.is_deleted', 0)
            ->where($variants->getTable() . '.is_deleted', 0)
            ->select($variants->getTable() . '.*');
    }
}
