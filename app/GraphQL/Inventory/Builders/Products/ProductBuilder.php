<?php

declare(strict_types=1);

namespace App\GraphQL\Inventory\Builders\Products;

use GraphQL\Type\Definition\ResolveInfo;
use Illuminate\Database\Eloquent\Builder;
use Kanvas\Connectors\RainForest\Workflows\SearchWorkflow as RainForestSearchWorkflow;
use Kanvas\Inventory\Products\Models\Products;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;
use Workflow\WorkflowStub;

class ProductBuilder
{
    public function getProducts(
        mixed $root,
        array $args,
        GraphQLContext $context,
        ResolveInfo $resolveInfo
    ): Builder {
        $company = auth()->user()->getCurrentCompany();
        $user = auth()->user();

        if (! $user->isAppOwner()) {
            //Products::setSearchIndex($company->getId());
        }

        $workflow = WorkflowStub::make(RainForestSearchWorkflow::class);
        $workflow->start();

        /**
         * @var Builder
         */
        return Products::query();
    }
}
