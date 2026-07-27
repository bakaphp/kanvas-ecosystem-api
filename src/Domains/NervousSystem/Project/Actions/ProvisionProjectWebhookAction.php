<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Project\Actions;

use Kanvas\NervousSystem\Project\Models\Project;
use Kanvas\NervousSystem\Project\Webhooks\ProcessProjectWebhookJob;
use Kanvas\Workflow\Actions\CreateReceiverWebhookAction;
use Kanvas\Workflow\DataTransferObject\ReceiverWebhook as ReceiverWebhookData;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Kanvas\Workflow\Models\WorkflowAction;
use Throwable;

/**
 * Provision the project's inbound webhook — its own `/receiver/{uuid}` endpoint that external
 * providers POST context to. The receiver is bound to `ProcessProjectWebhookJob` (via a WorkflowAction)
 * and carries `configuration.project_id` so the job knows which project to ingest into.
 *
 * Best-effort: a project still works without the webhook (context can be attached in-app); a failure
 * here must not block project creation.
 */
class ProvisionProjectWebhookAction
{
    public function __construct(
        private readonly Project $project,
    ) {
    }

    public function execute(): ?ReceiverWebhook
    {
        $owner = $this->project->user;
        if ($owner === null) {
            return null;
        }

        try {
            $action = WorkflowAction::firstOrCreate(
                ['model_name' => ProcessProjectWebhookJob::class],
                ['name' => 'Project Webhook Ingest'],
            );

            $receiver = new CreateReceiverWebhookAction(
                new ReceiverWebhookData(
                    app: $this->project->app,
                    company: $this->project->company,
                    user: $owner,
                    action: $action,
                    name: 'Project ' . $this->project->getId() . ' ingest',
                    description: 'Inbound context (transcript/email/mention) for "' . $this->project->title . '"',
                    configuration: ['project_id' => $this->project->getId()],
                    is_active: true,
                    run_async: true,
                ),
            )->execute();

            $this->project->receiver_webhook_id = $receiver->getId();
            $this->project->saveQuietly();

            return $receiver;
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }
}
