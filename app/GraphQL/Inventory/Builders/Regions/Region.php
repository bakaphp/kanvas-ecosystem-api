<?php
declare(strict_types=1);

namespace App\GraphQL\Inventory\Builders\Regions;

use GraphQL\Type\Definition\ResolveInfo;
use Illuminate\Database\Eloquent\Builder;
use Kanvas\Apps\Models\Apps;
use Kanvas\Inventory\Regions\Models\Regions as RegionModel;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

class Region
{
    /**
     * all.
     *
     * @param  mixed $root
     * @param  array $args
     * @param  GraphQLContext $context
     * @param  ResolveInfo $resolveInfo
     *
     * @return Builder
     */
    public function all(mixed $root, array $args, GraphQLContext $context, ResolveInfo $resolveInfo): Builder
    {
        return RegionModel::where('companies_id', $context->user()->getCurrentCompany()->getId())
                ->where('apps_id', app(Apps::class)->id);
    }
}
