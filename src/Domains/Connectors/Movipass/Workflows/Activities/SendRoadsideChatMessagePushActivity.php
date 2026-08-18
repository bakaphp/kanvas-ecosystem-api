<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Workflows\Activities;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Movipass\Actions\SendRoadsideChatMessagePushAction;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;

/**
 * Pushes a new roadside-assistance chat message to the counterparty.
 *
 * Bind a Rule on Channel + `updated`: Channel::addMessage() fires that event with the new
 * message in $params['message'] whichever way the client sent it (channel_slug or distribution).
 * Message + `created` is unreliable here — on the distribution path it fires before the message
 * is attached to a channel, so the recipient list is empty.
 */
#[WorkflowAction(
    name: 'Movipass Roadside Chat Push',
    description: 'Sends a push notification for a roadside-assistance chat message so the driver sees it. This '
        . 'REACHES the customer\'s device.',
)]
class SendRoadsideChatMessagePushActivity extends KanvasActivity
{
    public $tries = 2;

    public function execute(Channel $entity, Apps $app, array $params): array
    {
        $this->overwriteAppService($app);

        $message = $params['message'] ?? null;

        // Guard first on the verb — the only reliable roadside-chat signal the client sends —
        // so an unrelated channel update never opens an empty integration-history row.
        if (! $message instanceof Message
            || $message->messageType?->verb !== SendRoadsideChatMessagePushAction::ROADSIDE_CHAT_VERB
        ) {
            return [
                'result' => false,
                'message' => 'Channel update is not a roadside-assistance chat message',
                'recipients_notified' => 0,
                'message_id' => $message instanceof Message ? $message->getId() : null,
            ];
        }

        return $this->executeIntegration(
            entity: $entity,
            app: $app,
            integration: IntegrationsEnum::MOVIPASS,
            additionalParams: $params,
            integrationOperation: function () use ($entity, $message): array {
                $notified = new SendRoadsideChatMessagePushAction($entity, $message)->execute();

                return [
                    'result' => $notified > 0,
                    'message' => sprintf('Roadside chat push sent to %d recipient(s)', $notified),
                    'recipients_notified' => $notified,
                    'message_id' => $message->getId(),
                ];
            },
            company: $entity->company,
        );
    }
}
