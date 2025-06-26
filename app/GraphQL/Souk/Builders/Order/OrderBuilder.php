<?php

declare(strict_types=1);

namespace App\GraphQL\Souk\Builders\Order;

use GraphQL\Type\Definition\ResolveInfo;
use Illuminate\Database\Eloquent\Builder;
use Kanvas\Inventory\Variants\Models\Variants;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

class OrderBuilder
{
    public function filterByItems(
        Builder $builder,
        ?bool $includeAllItems,
        mixed $root,
        array $args,
        GraphQLContext $context,
        ResolveInfo $resolveInfo
    ): Builder {
        $includeAllItems = (bool) ($args['includeAllItems'] ?? $includeAllItems);
        // Default to showing only published variants unless
        // includeAllItems is explicitly set to true
        if ($includeAllItems !== true) {
            $builder->where('is_public', true);
        }

        return $builder;
    }
}
