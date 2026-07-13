<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Workflows\Activities;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Movipass\Actions\SendRoadsideChatMessagePushAction;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;

/**
 * Sends the roadside-assistance chat push to the counterparty when a message is
 * added to an order channel. Bind a Rule on Channel + `updated` (the verb
 * Channel::addMessage fires) — the action self-guards on roadside order type,
 * so a broad channel rule is safe.
 */
#[WorkflowAction]
class SendRoadsideChatMessagePushActivity extends KanvasActivity
{
    public $tries = 2;

    public function execute(Channel $entity, Apps $app, array $params): array
    {
        $this->overwriteAppService($app);

        $message = $params['message'] ?? null;

        /** @var Companies $company */
        $company = $entity->company;

        return $this->executeIntegration(
            entity: $entity,
            app: $app,
            integration: IntegrationsEnum::INTERNAL,
            additionalParams: $params,
            integrationOperation: function () use ($entity, $message): array {
                if (! $message instanceof Message) {
                    return [
                        'result' => false,
                        'message' => 'No message provided in params',
                        'channel_id' => $entity->getId(),
                    ];
                }

                $notified = new SendRoadsideChatMessagePushAction($entity, $message)->execute();

                return [
                    'result' => $notified > 0,
                    'message' => sprintf('Roadside chat push sent to %d recipient(s)', $notified),
                    'recipients_notified' => $notified,
                    'channel_id' => $entity->getId(),
                    'message_id' => $message->getId(),
                ];
            },
            company: $company,
        );
    }
}
