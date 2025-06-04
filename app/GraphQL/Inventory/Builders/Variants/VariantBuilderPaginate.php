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
        $builder = parent::filterByPublished($builder, $includeUnpublished, $root, $args, $context, $resolveInfo);
        $builder->inRandomOrder()
            ->limit(1);

        return $builder;
    }
}
