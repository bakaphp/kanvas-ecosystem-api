<?php

declare(strict_types=1);

namespace App\GraphQL\Subscription\Builders;

use GraphQL\Type\Definition\ResolveInfo;
use Illuminate\Database\Eloquent\Builder;
use Kanvas\Subscription\Subscriptions\Models\Subscription;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

class SubscriptionBuilder
{
    public function getSubscriptions(
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
        return Subscription::query();
    }
}
