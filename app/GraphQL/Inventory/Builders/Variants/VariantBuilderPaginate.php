<?php

declare(strict_types=1);

namespace App\GraphQL\Inventory\Builders\Variants;

use GraphQL\Type\Definition\ResolveInfo;
use Illuminate\Database\Eloquent\Builder;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

class VariantBuilderPaginate extends VariantBuilder
{
    // @todo: Implement pagination logic if needed
    public function filterByPublished(
        Builder $builder,
        ?bool $includeUnpublished,
        mixed $root,
        array $args,
        GraphQLContext $context,
        ResolveInfo $resolveInfo
    ): Builder {
        $includeUnpublished = (bool) ($args['includeUnpublished'] ?? $includeUnpublished);
        return $root->variants()
            ->when($includeUnpublished !== true, function (Builder $query) {
                $query->where('is_published', true);
            });
    }
}
