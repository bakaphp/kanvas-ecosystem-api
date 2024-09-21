<?php

declare(strict_types=1);

namespace App\GraphQL\Subscription\Builders\Subscriptions;

use GraphQL\Type\Definition\ResolveInfo;
use Illuminate\Database\Eloquent\Builder;
use Kanvas\Apps\Models\Apps;
use Laravel\Cashier\Subscription;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

class SubscriptionBuilder
{
    public function getSubscriptions(
        mixed $root,
        array $args,
        GraphQLContext $context,
        ResolveInfo $resolveInfo
    ): Builder {
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $app = app(Apps::class);

        return Subscription::query()
            ->select('subscriptions.*') 
            ->join('apps_stripe_customers', 'apps_stripe_customers.id', '=', 'subscriptions.apps_stripe_customer_id')
            ->where('apps_stripe_customers.companies_id', $company->id)
            ->where('apps_stripe_customers.apps_id', $app->getId());    
        }
}
