<?php

declare(strict_types=1);

namespace App\GraphQL\Inventory\Builders\Channels;

use GraphQL\Type\Definition\ResolveInfo;
use Illuminate\Database\Eloquent\Builder;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Inventory\Channels\Models\Channels;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Products\Traits\SearchProductWorkflowTrait;
use Kanvas\Inventory\Regions\Models\Regions;
use Kanvas\Inventory\Variants\Models\Variants as ModelsVariants;
use Kanvas\Inventory\Variants\Models\VariantsChannels;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

class AllProductsPublishedOnChannel
{
    use SearchProductWorkflowTrait;

    public function allProductsPublishedInChannel(
        mixed $root,
        array $args,
        GraphQLContext $context,
        ResolveInfo $resolveInfo
    ): Builder {
        $channelUuid = $args['id'];

        $channel = Channels::getByUuid($channelUuid);
        $variants = new ModelsVariants();
        $variantsChannel = new VariantsChannels();
        $app = app(Apps::class);
        $companyBranch = app(CompaniesBranches::class);
        $region = Regions::getDefault($companyBranch->company, $app);

        $user = auth()->user();
        $this->fireSearch(
            $app,
            $user,
            $companyBranch,
            $region,
            $args['search'] ?? ''
        );
        $variantsChannelTable = $variantsChannel->getTable();
        $variantsTable = $variants->getTable();

        if (! empty($args['search'])) {
            $productIds = Products::search($args['search'])->keys();
            $query = Products::query()
                ->whereIn('products.id', $productIds)
                ->join($variantsTable, $variantsTable . '.products_id', '=', 'products.id')
                ->join($variantsChannelTable, $variantsChannelTable . '.products_variants_id', '=', $variantsTable . '.id')
                ->where($variantsChannelTable . '.channels_id', $channel->getId())
                ->where($variantsChannelTable . '.is_deleted', 0)
                ->where($variantsChannelTable . '.is_published', 1)
                ->select('products.*')
                ->distinct();

            return $query;
        } else {
            $query = Products::query()
                ->join($variantsTable, $variantsTable . '.products_id', '=', 'products.id')
                ->join($variantsChannelTable, $variantsChannelTable . '.products_variants_id', '=', $variantsTable . '.id')
                ->where($variantsChannelTable . '.channels_id', $channel->getId())
                ->where($variantsChannelTable . '.is_deleted', 0)
                ->where($variantsChannelTable . '.is_published', 1)
                ->select('products.*')
                ->distinct();

            return $query;
        }

        return $query;
    }
}
