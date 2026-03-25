<?php

declare(strict_types=1);

namespace App\GraphQL\Connector\OpenClaw\Subscriptions;

use Illuminate\Http\Request;
use Nuwave\Lighthouse\Execution\ResolveInfo;
use Nuwave\Lighthouse\Schema\Types\GraphQLSubscription;
use Nuwave\Lighthouse\Subscriptions\Subscriber;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

class AgentTelemetrySubscription extends GraphQLSubscription
{
    public function authorize(Subscriber $subscriber, Request $request): bool
    {
        return true;
    }

    public function filter(Subscriber $subscriber, mixed $root): bool
    {
        $deploymentId = $subscriber->args['deployment_id'] ?? null;

        if ($deploymentId === null) {
            return true;
        }

        return (string) ($root['deployment_id'] ?? '') === (string) $deploymentId;
    }

    public function resolve(
        mixed $root,
        array $args,
        GraphQLContext $context,
        ResolveInfo $resolveInfo
    ): mixed {
        return $root;
    }
}
