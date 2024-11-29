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
        $user = auth()->user();
        if (! $user->isAppOwner()) {
            //Products::setSearchIndex($company->getId());
        }
        $query = Products::query();

        if (! empty($args['variantAttributeValue'])) {
            $query->filterByVariantAttributeValue($args['variantAttributeValue']);
        }

        if(! empty($args['variantAttributeOrderBy'])){
            $order = $args['variantAttributeOrderBy'];
            $query->orderByVariantAttribute(
                $order['name'],
                $order['sort']
            );
        }

        return $query;
    }
}
