<?php

declare(strict_types=1);

namespace App\GraphQL\Guild\Subscriptions;

use Exception;
use Illuminate\Http\Request;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Users\Repositories\UsersRepository;
use Nuwave\Lighthouse\Execution\ResolveInfo;
use Nuwave\Lighthouse\Schema\Types\GraphQLSubscription;
use Nuwave\Lighthouse\Subscriptions\Subscriber;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

class LeadUpdatedSubscription extends GraphQLSubscription
{
    public function authorize(Subscriber $subscriber, Request $request): bool
    {
        return true;
    }

    public function filter(Subscriber $subscriber, mixed $root): bool
    {
        try {
            UsersRepository::belongsToThisApp($subscriber->context->user, $root->app, $root->company);
        } catch (Exception $e) {
            return true;
        }

        return true;
    }

    public function resolve(
        mixed $root,
        array $args,
        GraphQLContext $context,
        ResolveInfo $resolveInfo
    ): Lead {
        return $root;
    }
}
