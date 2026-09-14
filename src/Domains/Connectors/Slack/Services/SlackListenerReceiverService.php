<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Slack\Services;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\Connectors\Slack\Webhooks\ProcessSlackListenerWebhookJob;
use Kanvas\Users\Models\Users;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Kanvas\Workflow\Models\WorkflowAction;

/**
 * One listener per company, where SlackReceiverService is one per agent: a workspace has a single
 * conversation history, and a second receiver would only record it twice.
 *
 * Created on first manifest generation, not on connect — the manifest has to carry the request URL,
 * so the receiver must exist before the customer has any tokens to give us.
 */
class SlackListenerReceiverService
{
    public function forCompany(
        AppInterface $app,
        CompanyInterface $company,
        Users $user,
    ): ReceiverWebhook {
        $action = WorkflowAction::where('model_name', ProcessSlackListenerWebhookJob::class)->firstOrFail();

        return ReceiverWebhook::firstOrCreate(
            [
                'apps_id' => $app->getId(),
                'companies_id' => $company->getId(),
                'action_id' => $action->getId(),
            ],
            [
                'users_id' => $user->getId(),
                'name' => 'Slack Workspace Listener',
                'description' => 'Read-only ingest of every Slack conversation the listener bot can see.',
                'configuration' => [],
                'is_active' => true,
                'run_async' => true,
            ]
        );
    }

    public function findForCompany(AppInterface $app, CompanyInterface $company): ?ReceiverWebhook
    {
        $action = WorkflowAction::where('model_name', ProcessSlackListenerWebhookJob::class)->first();

        if ($action === null) {
            return null;
        }

        return ReceiverWebhook::query()
            ->where('action_id', $action->getId())
            ->fromApp($app)
            ->fromCompany($company)
            ->notDeleted()
            ->first();
    }
}
