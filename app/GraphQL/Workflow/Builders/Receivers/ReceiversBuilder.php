<?php

declare(strict_types=1);

namespace App\GraphQL\Workflow\Builders\Receivers;

use Illuminate\Database\Eloquent\Builder;
use Kanvas\Apps\Models\Apps;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Kanvas\Workflow\Models\ReceiverWebhookCall;
use Kanvas\Workflow\Models\WorkflowAction;

class ReceiversBuilder
{
    public function getReceiversHistory(mixed $root, array $args): Builder
    {
        $app = app(Apps::class);

        $receiversWebhookCalls = ReceiverWebhookCall::query()->whereRelation(
            'receiverWebhook',
            'apps_id',
            $app->getId()
        );

        return $receiversWebhookCalls;
    }

    public function getHasAction(mixed $root, array $args): Builder
    {
        $actionTable = WorkflowAction::getFullTableName();

        $receiversWebhookCallsTable = ReceiverWebhookCall::getFullTableName();
        $receiversWebhookTable = ReceiverWebhook::getFullTableName();

        $root->select([
            'receiver_webhook_calls.*',
            'receiver_webhook_calls.id as id'
        ])
        ->join($receiversWebhookTable, $receiversWebhookCallsTable . '.receiver_webhooks_id', '=', $receiversWebhookTable . '.id')
        ->join($actionTable, $receiversWebhookTable . '.action_id', '=', $actionTable . '.id')
        ->distinct();

        if (isset($args['HAS']['condition'])) {
            $column = $args['HAS']['condition']['column'] ?? null;
            $value = $args['HAS']['condition']['value'] ?? null;
            if ($column && $value) {
                $root->when(
                    $value,
                    fn ($query) =>
                    $query->where($actionTable . '.' . $column, $value)
                );
            }
        }

        return $root;
    }
}
