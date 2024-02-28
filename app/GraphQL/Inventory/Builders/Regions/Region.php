<?php

declare(strict_types=1);

namespace App\GraphQL\Inventory\Builders\Regions;

use GraphQL\Type\Definition\ResolveInfo;
use Illuminate\Database\Eloquent\Builder;
use Kanvas\Inventory\Regions\Models\Regions as RegionModel;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

class Region
{
    /**
     * all.
     */
    public function all(
        mixed $root,
        array $args,
        GraphQLContext $context,
        ResolveInfo $resolveInfo
    ): Builder {
        $user = auth()->user();

        return RegionModel::query();
    }
}
