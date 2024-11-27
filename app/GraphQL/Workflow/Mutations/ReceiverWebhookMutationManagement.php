<?php

declare(strict_types=1);

namespace App\GraphQL\Workflow\Mutations;

use Exception;
use Kanvas\Apps\Models\Apps;
use Kanvas\Workflow\Actions\ReplayAttemptWebhookCallAction;
use Kanvas\Workflow\Models\ReceiverWebhookCall;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

class ReceiverWebhookMutationManagement
{
    public function replyWebhookCall(mixed $root, array $args, GraphQLContext $context): string
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
        $action = new ReplayAttemptWebhookCallAction($receiverWebhookCall);
        $action->execute();

        return 'Webhook will to replay';
    }
}
