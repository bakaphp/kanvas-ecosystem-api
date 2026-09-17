<?php

declare(strict_types=1);

namespace App\GraphQL\Guild\Queries\Leads;

use GraphQL\Deferred;
use GraphQL\Type\Definition\ResolveInfo;
use Kanvas\Guild\Leads\Models\LeadReceiver;
use Kanvas\Guild\Leads\Services\LeadReceiverWebhookLoader;
use Nuwave\Lighthouse\Execution\BatchLoader\BatchLoaderRegistry;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

class LeadReceiverWebhookQuery
{
    public function submitWebhookUuid(
        LeadReceiver $receiver,
        array $args,
        GraphQLContext $context,
        ResolveInfo $resolveInfo
    ): Deferred {
        // One loader per field path, so every receiver under the same list shares a single lookup.
        $loader = BatchLoaderRegistry::instance(
            $resolveInfo->path,
            fn (): LeadReceiverWebhookLoader => new LeadReceiverWebhookLoader(),
        );

        return $loader->load($receiver);
    }
}
