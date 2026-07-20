<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Workflows\Activities;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Movipass\Actions\SendRoadsideChatMessagePushAction;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\KanvasActivity;

/**
 * Pushes a new roadside-assistance chat message to the counterparty.
 *
 * Bind a Rule on Message + `created` — the verb every in-app message fires
 * (CreateMessageAction and the createMessage mutation). The message is the entity,
 * so the workflow serializer restores it reliably and we never depend on a model
 * surviving inside `$params`. This mirrors the platform's other push-on-message
 * activities (SendMessageNotificationToFollowersActivity). No `executeIntegration`
 * wrapper: this is a pure internal push (no external call), so gating it on an
 * IntegrationsCompany row would only add a per-message integration-history row and a
 * silent no-op when that gate integration isn't configured. The action self-guards
 * on roadside-assistance order channels, so a broad Message/created rule is safe.
 */
#[WorkflowAction]
class SendRoadsideChatMessagePushActivity extends KanvasActivity
{
    public $tries = 2;

    public function execute(Message $entity, Apps $app, array $params): array
    {
        $this->overwriteAppService($app);

        $notified = 0;

        foreach ($entity->channels as $channel) {
            $notified += new SendRoadsideChatMessagePushAction($channel, $entity)->execute();
        }

        return [
            'result' => $notified > 0,
            'message' => sprintf('Roadside chat push sent to %d recipient(s)', $notified),
            'recipients_notified' => $notified,
            'message_id' => $entity->getId(),
        ];
    }
}
