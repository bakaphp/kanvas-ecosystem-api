<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Workflows\Activities;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Movipass\Actions\SendRoadsideChatMessagePushAction;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;

/**
 * Pushes a new roadside-assistance chat message to the counterparty.
 */
#[WorkflowAction]
class SendRoadsideChatMessagePushActivity extends KanvasActivity
{
    public $tries = 2;

    public function execute(Message $entity, Apps $app, array $params): array
    {
        $this->overwriteAppService($app);

        // Guard first on the verb — the only reliable roadside-chat signal the client sends —
        // so an unrelated in-app message never opens an empty integration-history row.
        if ($entity->messageType?->verb !== SendRoadsideChatMessagePushAction::ROADSIDE_CHAT_VERB) {
            return [
                'result' => false,
                'message' => 'Message is not a roadside-assistance chat message',
                'recipients_notified' => 0,
                'message_id' => $entity->getId(),
            ];
        }

        return $this->executeIntegration(
            entity: $entity,
            app: $app,
            integration: IntegrationsEnum::MOVIPASS,
            additionalParams: $params,
            integrationOperation: function () use ($entity): array {
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
            },
            company: $entity->company,
        );
    }
}
