<?php

declare(strict_types=1);

namespace Kanvas\Connectors\RespondIO\Webhooks;

use Kanvas\Connectors\RespondIO\Actions\ProcessCallEndedAction;
use Kanvas\Connectors\RespondIO\Actions\ProcessCommentAction;
use Kanvas\Connectors\RespondIO\Actions\ProcessContactAssigneeUpdatedAction;
use Kanvas\Connectors\RespondIO\Actions\ProcessContactLifecycleUpdatedAction;
use Kanvas\Connectors\RespondIO\Actions\ProcessContactTagUpdatedAction;
use Kanvas\Connectors\RespondIO\Actions\ProcessContactUpdatedAction;
use Kanvas\Connectors\RespondIO\Actions\ProcessConversationClosedAction;
use Kanvas\Connectors\RespondIO\Actions\ProcessConversationOpenedAction;
use Kanvas\Connectors\RespondIO\Actions\ProcessIncomingMessageAction;
use Kanvas\Connectors\RespondIO\Actions\ProcessOutgoingMessageAction;
use Kanvas\Connectors\RespondIO\Enums\WebhookEventEnum;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\Jobs\ProcessWebhookJob;
use Override;

#[WorkflowAction(
    name: 'RespondIO Inbound Webhook',
    description: 'Receiver for everything Respond.io reports — messages in and out, comments, conversations '
        . 'opening and closing, contact and tag changes, ended calls — and files each against the right '
        . 'Kanvas record. Inbound only; it records what happened and replies to nobody. The agent reply '
        . 'is a separate responder step.',
    integration: IntegrationsEnum::RESPOND_IO,
)]
class ProcessRespondIOWebhookJob extends ProcessWebhookJob
{
    #[Override]
    public function execute(): array
    {
        /** @var array<string, mixed> $payload */
        $payload = $this->webhookRequest->payload;
        /** @var string $eventType */
        $eventType = $payload['event_type'] ?? 'unknown';

        $app = $this->receiver->app;
        $company = $this->receiver->company;
        $user = $this->receiver->user;
        $configuration = $this->receiver->configuration;

        $result = match ($eventType) {
            WebhookEventEnum::MESSAGE_RECEIVED->value => new ProcessIncomingMessageAction(
                $app,
                $company,
                $user,
                $payload,
                $configuration,
            )->execute(),

            WebhookEventEnum::MESSAGE_SENT->value => new ProcessOutgoingMessageAction(
                $app,
                $company,
                $user,
                $payload,
            )->execute(),

            WebhookEventEnum::COMMENT_CREATED->value => new ProcessCommentAction(
                $app,
                $company,
                $user,
                $payload,
            )->execute(),

            WebhookEventEnum::CONVERSATION_OPENED->value => new ProcessConversationOpenedAction(
                $app,
                $company,
                $user,
                $payload,
            )->execute(),

            WebhookEventEnum::CONVERSATION_CLOSED->value => new ProcessConversationClosedAction(
                $app,
                $company,
                $user,
                $payload,
            )->execute(),

            WebhookEventEnum::CONTACT_UPDATED->value => new ProcessContactUpdatedAction(
                $app,
                $company,
                $user,
                $payload,
            )->execute(),

            WebhookEventEnum::CONTACT_TAG_UPDATED->value => new ProcessContactTagUpdatedAction(
                $app,
                $company,
                $user,
                $payload,
            )->execute(),

            WebhookEventEnum::CONTACT_ASSIGNEE_UPDATED->value => new ProcessContactAssigneeUpdatedAction(
                $app,
                $company,
                $user,
                $payload,
            )->execute(),

            WebhookEventEnum::CONTACT_LIFECYCLE_UPDATED->value => new ProcessContactLifecycleUpdatedAction(
                $app,
                $company,
                $user,
                $payload,
            )->execute(),

            WebhookEventEnum::CALL_ENDED->value => new ProcessCallEndedAction(
                $app,
                $company,
                $user,
                $payload,
            )->execute(),

            default => [
                'event_type' => $eventType,
                'status' => 'acknowledged',
            ],
        };

        return [
            'message' => 'RespondIO webhook processed successfully',
            'event_type' => $eventType,
            'result' => $result,
        ];
    }
}
