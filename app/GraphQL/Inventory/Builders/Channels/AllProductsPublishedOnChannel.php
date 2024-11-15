<?php

declare(strict_types=1);

namespace App\GraphQL\Inventory\Builders\Channels;

use GraphQL\Type\Definition\ResolveInfo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Enums\AppEnums;
use Kanvas\Inventory\Channels\Models\Channels;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Regions\Models\Regions;
use Kanvas\Inventory\Variants\Models\Variants as ModelsVariants;
use Kanvas\Inventory\Variants\Models\VariantsChannels;
use Kanvas\Users\Repositories\UsersRepository;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;
use Kanvas\Inventory\Products\Traits\SearchProductWorkflowTrait;

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

        $channelUuid = $args['id'];

        $channel = Channels::getByUuid($channelUuid);
        $variants = new ModelsVariants();
        $variantsChannel = new VariantsChannels();
        $app = app(Apps::class);
        $companyBranch = app(CompaniesBranches::class);
        $region = Regions::getDefault($companyBranch->company, $app);
        if (! $userId = $app->get(AppEnums::fromName('DEFAULT_PUBLIC_SEARCH_USER_ID'))) {
            throw new ModelNotFoundException('User default search not configured');
        }
        $user = UsersRepository::getUserOfAppById($userId, $app);
        $this->fireSearch(
            $app,
            $user,
            $companyBranch,
            $region,
            $args['search'] ?? ''
        );
        $variantsChannelTable = $variantsChannel->getTable();
        $query = Products::query()
                ->join($variants->getTable(), $variants->getTable() . '.products_id', '=', 'products.id')
                ->join($variantsChannelTable, $variantsChannelTable . '.products_variants_id', '=', $variants->getTable() . '.id')
                ->where($variantsChannelTable . '.channels_id', $channel->getId())
                ->where($variantsChannelTable . '.is_deleted', 0)
                ->where($variantsChannelTable . '.is_published', 1)
                ->select('products.*')
                ->distinct();

        return $query;
    }
}
