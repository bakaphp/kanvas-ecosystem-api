<?php

declare(strict_types=1);

namespace App\GraphQL\Inventory\Builders\Products;

use GraphQL\Type\Definition\ResolveInfo;
use Illuminate\Database\Eloquent\Builder;
use Kanvas\Inventory\Products\Models\Products;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

class ProductBuilder
{
    public function getProducts(
        mixed $root,
        array $args,
        GraphQLContext $context,
        ResolveInfo $resolveInfo
    ): Builder {
        $company = auth()->user()->getCurrentCompany();

        Products::setSearchIndex($company->getId());

        /**
         * @var Builder
         */
        return Products::query();
    }
}
