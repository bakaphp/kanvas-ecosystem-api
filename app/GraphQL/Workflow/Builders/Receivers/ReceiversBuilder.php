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
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $receiversWebhookCalls = ReceiverWebhookCall::query()
            ->join('receiver_webhooks', 'receiver_webhook_calls.receiver_webhooks_id', '=', 'receiver_webhooks.id')
            ->where('receiver_webhooks.apps_id', $app->getId())
            ->where('receiver_webhooks.companies_id', $company->getId())
            ->where('receiver_webhooks.is_deleted', 0)
            ->where('receiver_webhook_calls.is_deleted', 0)
            ->select([
                'receiver_webhook_calls.*',
                'receiver_webhooks.name',
            ]);

        return $receiversWebhookCalls;
    }

    public function getHasAction(mixed $root, array $args): Builder
    {
        $actionTable = WorkflowAction::getFullTableName();

        $receiversWebhookCallsTable = ReceiverWebhookCall::getFullTableName();
        $receiversWebhookTable = ReceiverWebhook::getFullTableName();

        $root->select([
            'receiver_webhook_calls.*',
            'receiver_webhook_calls.id as id',
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
