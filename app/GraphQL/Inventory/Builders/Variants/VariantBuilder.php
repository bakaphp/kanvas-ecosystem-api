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
        $company = auth()->user()->getCurrentCompany();

        if (! $user->isAppOwner()) {
            Variants::setSearchIndex($company->getId());
        }
        /**
         * @var Builder
         */
        return Variants::query();
    }
}
