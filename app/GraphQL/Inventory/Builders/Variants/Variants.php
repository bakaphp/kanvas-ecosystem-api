<?php

declare(strict_types=1);

namespace App\GraphQL\Inventory\Builders\Variants;

use GraphQL\Type\Definition\ResolveInfo;
use Illuminate\Database\Eloquent\Builder;
use Kanvas\Inventory\Channels\Models\Channels;
use Kanvas\Inventory\Variants\Models\Variants as ModelsVariants;
use Kanvas\Inventory\Variants\Models\VariantsChannels;
use Kanvas\Inventory\Variants\Models\VariantsWarehouses;
use Kanvas\Inventory\Warehouses\Models\Warehouses;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

class Variants
{
    public function allVariantsPublishedInChannel(
        mixed $root,
        array $args,
        GraphQLContext $context,
        ResolveInfo $resolveInfo
    ): Builder {
        $channelUuid = $args['id'];

        $channel = Channels::getByUuid($channelUuid);
        $variants = new ModelsVariants();
        $variantWarehouse = new VariantsWarehouses();
        $variantsChannel = new VariantsChannels();

        //set index
        ModelsVariants::setSearchIndex((int) $channel->companies_id);

        return ModelsVariants::join($variantWarehouse->getTable(), $variantWarehouse->getTable() . '.products_variants_id', '=', $variants->getTable() . '.id')
            ->join($variantsChannel->getTable(), $variantsChannel->getTable() . '.product_variants_warehouse_id', '=', $variantWarehouse->getTable() . '.id')
            ->join($channel->getTable(), $channel->getTable() . '.id', '=', $variantsChannel->getTable() . '.channels_id')
            ->where($variantsChannel->getTable() . '.channels_id', $channel->getId())
            ->where($variantsChannel->getTable() . '.is_deleted', 0)
            ->where($variantsChannel->getTable() . '.is_published', 1);
    }

    public function allVariantsInWarehouse(
        mixed $root,
        array $args,
        GraphQLContext $context,
        ResolveInfo $resolveInfo
    ): Builder {
        $warehouseId = $args['warehouseId'];

        $warehouse = Warehouses::fromApp()
                    ->fromCompany(auth()->user()->getCurrentCompany())
                    ->where('id', $warehouseId)->firstOrFail();

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
}
