<?php

declare(strict_types=1);

namespace App\GraphQL\Workflow\Mutations;

use Exception;
use Kanvas\Apps\Models\Apps;
use Kanvas\Workflow\Actions\RetryWebhookCallAction;
use Kanvas\Workflow\Models\ReceiverWebhookCall;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

class ReceiverWebhookMutationManagement
{
    public function retryWebhookCall(mixed $root, array $args, GraphQLContext $context): bool
    {
        $app = app(Apps::class);
        $receiverWebhookCall = ReceiverWebhookCall::whereRelation(
            'receiverWebhook',
            'apps_id',
            $app->getId()
        )
        ->find($args['id']);

        if (! $receiverWebhookCall) {
            throw new Exception('Webhook call not found');
        }

        $action = new RetryWebhookCallAction($receiverWebhookCall);
        $action->execute();

        return true;
    }
}
