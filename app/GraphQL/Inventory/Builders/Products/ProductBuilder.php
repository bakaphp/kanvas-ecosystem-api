<?php

declare(strict_types=1);

namespace App\GraphQL\Inventory\Builders\Products;

use GraphQL\Type\Definition\ResolveInfo;
use Illuminate\Database\Eloquent\Builder;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Connectors\RainForest\Workflows\SearchWorkflow as RainForestSearchWorkflow;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Regions\Models\Regions;
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
        $app = app(Apps::class);
        $companyBranch = app(CompaniesBranches::class);
        if ($companyBranch && key_exists('search', $args)) {
            $region = Regions::getDefault($companyBranch->company);
            $workflow = WorkflowStub::make(RainForestSearchWorkflow::class);
            $workflow->start($app, auth()->user(), $companyBranch, $region, $args['search']);
        }

        $company = auth()->user()->getCurrentCompany();
        $user = auth()->user();

        if (! $user->isAppOwner()) {
            //Products::setSearchIndex($company->getId());
        }

        /**
         * @var Builder
         */
        return Products::query();
    }
}
