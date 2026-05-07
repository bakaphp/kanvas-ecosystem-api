<?php

declare(strict_types=1);

namespace App\GraphQL\Inventory\Builders\Variants;

use GraphQL\Type\Definition\ResolveInfo;
use Illuminate\Database\Eloquent\Builder;
use Kanvas\Apps\Models\Apps;
use Kanvas\Inventory\Variants\Models\Variants;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

class VariantBuilder
{
    public function getVariants(
        mixed $root,
        array $args,
        GraphQLContext $context,
        ResolveInfo $resolveInfo
    ): Builder {
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $app = app(Apps::class);

        if (! $user->isAppOwner()) {
            //Variants::setSearchIndex($company->getId());
        }

        $query = Variants::query();

        if (empty($args['orderBy']) && (bool) $app->get('product_variants_sort_by_weight')) {
            $query->orderBy('weight', 'asc');
        }

        return $query;
    }

    public function filterByPublished(
        Builder $builder,
        ?bool $includeUnpublished,
        mixed $root,
        array $args,
        GraphQLContext $context,
        ResolveInfo $resolveInfo
    ): Builder {
        $includeUnpublished = (bool) ($args['includeUnpublished'] ?? $includeUnpublished);
        $limit = (int) ($args['limit'] ?? 0);
        // Default to showing only published variants unless
        // includeUnpublished is explicitly set to true
        if ($includeUnpublished !== true) {
            $builder->where('is_published', true);
        }
        if ($limit) {
            $builder->limit($limit);
        }

        return $builder;
    }
}
