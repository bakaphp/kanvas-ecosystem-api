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

        // Check if we're querying for a specific ID - simplified condition check
        $isQueryingById = false;
        $where = $args['where'] ?? [];

        // Check top level
        if (isset($where['column']) && $where['column'] === 'receiver_webhook_calls.id' &&
            isset($where['operator']) && $where['operator'] === '=' && !empty($where['value'])) {
            $isQueryingById = true;
        }

        // Check AND array
        if (!$isQueryingById && isset($where['AND']) && is_array($where['AND'])) {
            foreach ($where['AND'] as $condition) {
                if (isset($condition['column']) && $condition['column'] === 'receiver_webhook_calls.id' &&
                    isset($condition['operator']) && $condition['operator'] === '=' && !empty($condition['value'])) {
                    $isQueryingById = true;

                    break;
                }
            }
        }

        $query = ReceiverWebhookCall::query()
            ->join('receiver_webhooks', 'receiver_webhook_calls.receiver_webhooks_id', '=', 'receiver_webhooks.id')
            ->where('receiver_webhooks.apps_id', $app->getId())
            ->where('receiver_webhooks.companies_id', $company->getId())
            ->where('receiver_webhooks.is_deleted', 0)
            ->where('receiver_webhook_calls.is_deleted', 0);

        // Apply where conditions - simplified
        if (isset($where['column']) && isset($where['operator']) && isset($where['value'])) {
            $query->where($where['column'], $where['operator'], $where['value']);
        }

        if (isset($where['AND']) && is_array($where['AND'])) {
            foreach ($where['AND'] as $condition) {
                if (isset($condition['column']) && isset($condition['operator']) && isset($condition['value'])) {
                    $query->where($condition['column'], $condition['operator'], $condition['value']);
                }
            }
        }

        // Select columns based on query type
        if ($isQueryingById) {
            $query->select([
                'receiver_webhook_calls.*',
                'receiver_webhooks.name',
            ]);
        } else {
            $query->select([
                'receiver_webhook_calls.id',
                'receiver_webhook_calls.uuid',
                'receiver_webhook_calls.url',
                'receiver_webhook_calls.receiver_webhooks_id',
                'receiver_webhook_calls.status',
                'receiver_webhook_calls.created_at',
                'receiver_webhook_calls.updated_at',
                'receiver_webhook_calls.is_deleted',
                'receiver_webhooks.name',
            ]);
        }

        // Apply ordering - simplified
        if (isset($args['orderBy']) && is_array($args['orderBy'])) {
            foreach ($args['orderBy'] as $orderBy) {
                if (isset($orderBy['column']) && isset($orderBy['order'])) {
                    $query->orderBy($orderBy['column'], $orderBy['order']);
                }
            }
        }

        return $query;
    }

    public function getHasAction(mixed $root, array $args): Builder
    {
        $actionTable = WorkflowAction::getFullTableName();

        $receiversWebhookCallsTable = ReceiverWebhookCall::getFullTableName();
        $receiversWebhookTable = ReceiverWebhook::getFullTableName();

        $receiversWebhookAlias = 'rw_has_action';
        $receiversWebhookTable = ReceiverWebhook::getFullTableName();

        $root->select([
            "$receiversWebhookCallsTable.*",
            "$receiversWebhookCallsTable.id as id",
        ])
        ->join("$receiversWebhookTable as $receiversWebhookAlias", "$receiversWebhookCallsTable.receiver_webhooks_id", '=', "$receiversWebhookAlias.id")
        ->join($actionTable, "$receiversWebhookAlias.action_id", '=', "$actionTable.id")
        ->distinct();

        if (isset($args['HAS']['condition'])) {
            $column = $args['HAS']['condition']['column'] ?? null;
            $value = $args['HAS']['condition']['value'] ?? null;
            if ($column && $value) {
                $root->when(
                    $value,
                    fn ($query) => $query->where("$actionTable.$column", $value)
                );
            }
        }

        return $root;
    }
}
