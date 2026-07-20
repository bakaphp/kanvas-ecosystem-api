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
