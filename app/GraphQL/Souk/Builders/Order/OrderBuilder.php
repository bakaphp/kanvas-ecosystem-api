<?php

declare(strict_types=1);

namespace App\GraphQL\Souk\Builders\Order;

use GraphQL\Type\Definition\ResolveInfo;
use Illuminate\Database\Eloquent\Builder;
use Kanvas\Souk\Orders\Models\Order;
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

    /**
     * Apply provider company filter for provider dashboard queries.
     * When used without model parameter, this must return a fresh query builder.
     */
    public function applyProviderFilter(
        $root,
        array $args
    ): Builder {
        $query = Order::query();

        if (isset($args['whereHasProvider'])) {
            $companyId = (int) $args['whereHasProvider'];
            $query->whereHas('providerCompanies', function ($q) use ($companyId) {
                $q->where('companies.id', $companyId);
            });
        }

        return $query;
    }
}
