<?php

declare(strict_types=1);

namespace App\GraphQL\Inventory\Builders\Variants;

use GraphQL\Type\Definition\ResolveInfo;
use Illuminate\Database\Eloquent\Builder;
use Kanvas\Apps\Models\Apps;
use Kanvas\Inventory\Channels\Models\Channels;
use Kanvas\Inventory\Variants\Models\Variants as ModelsVariants;
use Kanvas\Inventory\Variants\Models\VariantsChannels;
use Kanvas\Inventory\Variants\Models\VariantsWarehouses;
use Kanvas\Inventory\Warehouses\Models\Warehouses;
use Kanvas\Social\Interactions\Models\EntityInteractions;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

class Variants
{
    public function allVariantsPublishedInChannel(mixed $root, array $args, GraphQLContext $context, ResolveInfo $resolveInfo): Builder
    {
        $channelUuid = $args['id'];

        $channel = Channels::getByUuid($channelUuid);
        $variants = new ModelsVariants();
        $variantsChannel = new VariantsChannels();
        $entityInteractions = new EntityInteractions();

        //set index
        ModelsVariants::setSearchIndex((int) $channel->companies_id);

        /**
         * @var Builder
         * this sucks we need to figure out a better way to do this,
         * allow sort and merge of entities between diff subsystems
         * maybe send it denormalized to a third party vender supabase or something
         */
        if (app(Apps::class)->get('inventory_social_integration')) {
            return ModelsVariants::select($variants->getFullTableName() . '.*')
               ->join($variantsChannel->getFullTableName(), $variantsChannel->getFullTableName() . '.products_variants_id', '=', $variants->getFullTableName() . '.id')
               ->leftJoin($entityInteractions->getFullTableName(), $entityInteractions->getFullTableName() . '.interacted_entity_id', '=', $variants->getFullTableName() . '.uuid')
               ->where($variantsChannel->getFullTableName() . '.channels_id', $channel->getId())
               ->where($variantsChannel->getFullTableName() . '.is_deleted', 0)
               ->where($variantsChannel->getFullTableName() . '.is_published', 1)
               ->orderBy($entityInteractions->getFullTableName() . '.interactions_id', 'desc');
        }

        return ModelsVariants::join($variantsChannel->getTable(), $variantsChannel->getTable() . '.products_variants_id', '=', $variants->getTable() . '.id')
                ->where($variantsChannel->getTable() . '.channels_id', $channel->getId())
                ->where($variantsChannel->getTable() . '.is_deleted', 0)
                ->where($variantsChannel->getTable() . '.is_published', 1);
    }

    public function allVariantsInWarehouse(mixed $root, array $args, GraphQLContext $context, ResolveInfo $resolveInfo): Builder
    {
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
