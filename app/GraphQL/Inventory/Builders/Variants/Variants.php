<?php
declare(strict_types=1);

namespace App\GraphQL\Inventory\Builders\Variants;

use GraphQL\Type\Definition\ResolveInfo;
use Illuminate\Database\Eloquent\Builder;
use Kanvas\Inventory\Channels\Models\Channels;
use Kanvas\Inventory\Variants\Models\Variants as ModelsVariants;
use Kanvas\Inventory\Variants\Models\VariantsChannels;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

class Variants
{
    public function allAvailableViaChannel(mixed $root, array $args, GraphQLContext $context, ResolveInfo $resolveInfo) : Builder
    {
        $channelUuid = $args['id'];

        $channel = Channels::getByUuid($channelUuid);
        $variants = new ModelsVariants();
        $variantsChannel = new VariantsChannels();

        return ModelsVariants::join($variantsChannel->getTable(), $variantsChannel->getTable() . '.products_variants_id', '=', $variants->getTable() . '.id')
            ->where($variantsChannel->getTable() . '.channels_id', $channel->getId())
            ->where($variantsChannel->getTable() . '.is_deleted', 0)
            ->where($variantsChannel->getTable() . '.is_published', 1);
    }
}
