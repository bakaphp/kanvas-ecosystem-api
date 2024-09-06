<?php

declare(strict_types=1);

namespace App\GraphQL\Subscription\Builders;

use GraphQL\Type\Definition\ResolveInfo;
use Illuminate\Database\Eloquent\Builder;
use Kanvas\Subscription\Plans\Models\Plan;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

class PlanBuilder
{
    public function getPlans(
        mixed $root,
        array $args,
        GraphQLContext $context,
        ResolveInfo $resolveInfo
    ): Builder {
        $user = auth()->user;
        $company = $user->getCurrentCompany();

        if (! $user->isAppOwner()) {
            //Subscription::setSearchIndex($company->getId());
        }

        /**
         * @var Builder
         */
        return Plan::query();
    }
}