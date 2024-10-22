<?php

declare(strict_types=1);

namespace App\GraphQL\Inventory\Builders\Products;

use GraphQL\Type\Definition\ResolveInfo;
use Illuminate\Database\Eloquent\Builder;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\CompaniesBranches;
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
        $app = app(Apps::class);
        $companyBranch = app(CompaniesBranches::class);
        $company = auth()->user()->getCurrentCompany();
        $user = auth()->user();

        if ($companyBranch && key_exists('search', $args)) {
            $region = Regions::getDefault($companyBranch->company, $app);
            $app->fireWorkflow(event: WorkflowEnum::SEARCH->value, params: [
                'app' => $app,
                'user' => auth()->user(),
                'companyBranch' => $companyBranch,
                'region' => $region,
                'search' => $args['search'],
            ]);
        }

        if (! $user->isAppOwner()) {
            //Products::setSearchIndex($company->getId());
        }

        /**
         * @var Builder
         */
        return Products::query();
    }
}
