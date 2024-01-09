<?php

declare(strict_types=1);

namespace App\GraphQL\Inventory\Builders\Variants;

use GraphQL\Type\Definition\ResolveInfo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Kanvas\Inventory\Channels\Models\Channels;
use Kanvas\Inventory\Variants\Models\Variants as ModelsVariants;
use Kanvas\Inventory\Variants\Models\VariantsChannels;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;
use stdClass;

class VariantChannelBuilder
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
        $variantsChannel = new VariantsChannels();

        //set index
        ModelsVariants::setSearchIndex((int) $channel->companies_id);

        /**
         * @var Builder
         */
        return ModelsVariants::select(
            $variants->getTable() . '.*',
            DB::raw("'{$channel->name}' as channel_name"), //add channel name
            $variantsChannel->getTable() . '.price',
            $variantsChannel->getTable() . '.discounted_price',
            $variantsChannel->getTable() . '.is_published',
        )
        ->join($variantsChannel->getTable(), $variantsChannel->getTable() . '.products_variants_id', '=', $variants->getTable() . '.id')
        ->where($variantsChannel->getTable() . '.channels_id', $channel->getId())
        ->where($variantsChannel->getTable() . '.is_deleted', 0)
        ->where($variantsChannel->getTable() . '.is_published', 1);
    }

    /**
     * Format channel data from builder
     */
    public function getChannel(mixed $root, array $req): array
    {
        //@todo send the channel via header
        if (! isset($root->channel_name)) {
            try {
                $defaultChannelVariant = $root->getPriceInfoFromDefaultChannel();
                $root = new stdClass();
                $root->channel_name = $defaultChannelVariant->name;
                $root->price = $defaultChannelVariant->pivot->price;
                $root->discounted_price = $defaultChannelVariant->pivot->discounted_price;
                $root->is_published = $defaultChannelVariant->pivot->is_published;
            } catch(ModelNotFoundException $e) {
            }
        }

        //@todo doesnt work with search
        return [
            'name' => $root->channel_name,
            'price' => $root->price,
            'discounted_price' => $root->discounted_price,
            'is_published' => $root->is_published,
        ];
    }
}
