<?php

declare(strict_types=1);

namespace App\GraphQL\Intelligence\Subscriptions;

use Illuminate\Http\Request;
use Nuwave\Lighthouse\Execution\ResolveInfo;
use Nuwave\Lighthouse\Schema\Types\GraphQLSubscription;
use Nuwave\Lighthouse\Subscriptions\Subscriber;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;
use Override;

class AgentChatSubscription extends GraphQLSubscription
{
    #[Override]
    public function authorize(Subscriber $subscriber, Request $request): bool
    {
        return true;
    }

    #[Override]
    public function filter(Subscriber $subscriber, mixed $root): bool
    {
        $args = $subscriber->args;

        /** @var array<string, mixed> $data */
        $data = $root;

        return ($data['agent_id'] ?? null) == ($args['agent_id'] ?? null)
            && ($data['session_id'] ?? null) === ($args['session_id'] ?? null);
    }

    #[Override]
    public function resolve(
        mixed $root,
        array $args,
        GraphQLContext $context,
        ResolveInfo $resolveInfo,
    ): mixed {
        return $root;
    }
}
