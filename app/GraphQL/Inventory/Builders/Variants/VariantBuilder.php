<?php

declare(strict_types=1);

namespace App\GraphQL\Inventory\Builders\Variants;

use GraphQL\Type\Definition\ResolveInfo;
use Illuminate\Database\Eloquent\Builder;
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

        if (!$user->isAppOwner()) {
            //Variants::setSearchIndex($company->getId());
        }

        /**
         * @var Builder
         */
        return Variants::query();
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
        // Default to showing only published variants unless
        // includeUnpublished is explicitly set to true
        if ($includeUnpublished !== true) {
            $builder->where('is_published', true);
        }

        return $builder;
    }
}
