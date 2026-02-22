<?php

declare(strict_types=1);

namespace App\GraphQL\Subscription\Queries;

use Kanvas\Apps\Models\Apps;
use Laravel\Cashier\Subscription;

class UserSubscriptionQuery
{
    public function getById(mixed $root, array $args): Subscription
    {
        /** @var \Kanvas\Users\Models\Users $user */
        $user = auth()->user();
        $app = app(Apps::class);

        $query = Subscription::query()
            ->select('subscriptions.*')
            ->join('apps_stripe_customers', 'apps_stripe_customers.id', '=', 'subscriptions.apps_stripe_customer_id')
            ->where('apps_stripe_customers.apps_id', $app->getId())
            ->where('apps_stripe_customers.companies_id', 0)
            ->where('subscriptions.id', $args['id']);

        if (! $user->isAppOwner()) {
            $query->where('apps_stripe_customers.users_id', $user->getId());
        }

        return $query->firstOrFail();
    }
}
