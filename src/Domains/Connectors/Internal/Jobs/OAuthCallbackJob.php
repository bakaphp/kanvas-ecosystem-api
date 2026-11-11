<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Internal\Jobs;

use Kanvas\Exceptions\ValidationException;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\Jobs\ProcessWebhookJob;
use Kanvas\Workflow\Models\WorkflowAction as WorkflowActionModel;
use Override;

#[WorkflowAction(
    name: 'OAuth Callback Receiver',
    description: 'Landing endpoint for an external OAuth redirect: it acknowledges the callback and hands back '
        . 'the payload. Infrastructure for connecting an account, not a step to attach to a record.',
    integration: IntegrationsEnum::INTERNAL,
)]
class OAuthCallbackJob extends ProcessWebhookJob
{
    /**
     * The action every OAuth consent receiver points at.
     */
    public static function workflowAction(): WorkflowActionModel
    {
        $action = WorkflowActionModel::query()->where('model_name', self::class)->first();

        if (! $action instanceof WorkflowActionModel) {
            throw new ValidationException('The OAuth callback workflow action is not registered — run kanvas:workflow-sync-actions.');
        }

        return $action;
    }

    #[Override]
    public function execute(): array
    {
        return [
            'message' => 'OAuth callback processed via receiver webhook',
            'payload' => $this->webhookRequest->payload,
        ];
    }
}
