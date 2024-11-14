<?php

declare(strict_types=1);

namespace App\GraphQL\Inventory\Builders\Products;

use GraphQL\Type\Definition\ResolveInfo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Enums\AppEnums;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Regions\Models\Regions;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

class ProductBuilder
{
    public function getProducts(
        mixed $root,
        array $args,
        GraphQLContext $context,
        ResolveInfo $resolveInfo
    ): Builder {


        if (! $user->isAppOwner()) {
            //Products::setSearchIndex($company->getId());
        }
        $query = Products::query();

        if (! empty($args['variantAttributeValue'])) {
            $query->filterByVariantAttributeValue($args['variantAttributeValue']);
        }

        return $query;
    }
}
