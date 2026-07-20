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

        $pendingPushes = [];
        foreach ($entity->channels as $channel) {
            $push = new SendRoadsideChatMessagePushAction($channel, $entity);
            if ($push->getRoadsideOrder() !== null) {
                $pendingPushes[] = $push;
            }
        }

        if ($pendingPushes === []) {
            return [
                'result' => false,
                'message' => 'Message is not part of a roadside-assistance channel',
                'recipients_notified' => 0,
                'message_id' => $entity->getId(),
            ];
        }

        return $this->executeIntegration(
            entity: $entity,
            app: $app,
            integration: IntegrationsEnum::MOVIPASS,
            additionalParams: $params,
            integrationOperation: function () use ($pendingPushes, $entity): array {
                $notified = 0;

                foreach ($pendingPushes as $push) {
                    $notified += $push->execute();
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
