<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Workflows;

use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Leads\Actions\Communication\DeliverConversationMessageAction;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;

/**
 * Sends the customer a message an AI running outside Kanvas filed in a lead's conversation. The
 * external orchestrator marks it `from_me`, `from_ia` and `from_orchestrator`; for now both AI
 * flags must be set.
 *
 * The delivery is the one a person's reply takes (DeliverConversationMessageAction), without the
 * human takeover: the author is another AI, so the local AI is not paused.
 */
#[WorkflowAction(
    name: 'External Agent Channel Response',
    description: 'Delivers an agent reply that was produced elsewhere back onto the channel it belongs to. '
        . 'Part of the external/async responder path — you rarely wire this by hand.',
)]
class ExternalAgentChannelResponseActivity extends KanvasActivity
{
    public $tries = 3;

    public function execute(Channel $channel, Apps $app, array $params): array
    {
        $this->overwriteAppService($app);
        $message = $params['message'] ?? null;
        $context = [
            'channel_id' => $channel->getId(),
            'channel_slug' => $channel->slug ?? null,
            'channel_entity_type' => $channel->entity_namespace ?? null,
            'channel_entity_id' => $channel->entity_id ?? null,
            'from' => $params['from'] ?? null,
        ];

        if (! $message instanceof Message) {
            return $this->failWorkflow([
                'message' => 'Message not found',
                'entity' => null,
                'context' => $context,
            ]);
        }

        $context += [
            'message_id' => $message->getId(),
            'message_type' => $message->messageType?->verb,
        ];

        return $this->executeIntegration(
            entity: $channel,
            app: $app,
            integration: IntegrationsEnum::INTERNAL,
            integrationOperation: function () use ($channel, $message, $params, $context): array {
                $fromExternalAi = (bool) ($message->message['from_ia'] ?? false)
                    && (bool) ($message->message['from_orchestrator'] ?? false);

                if (! $fromExternalAi) {
                    return $this->failWorkflow([
                        'message' => 'Message is not from an external AI (from_ia / from_orchestrator)',
                        'entity' => null,
                        'context' => $context,
                    ]);
                }

                $delivery = new DeliverConversationMessageAction(
                    $channel,
                    $message,
                    writtenByPerson: false
                )->execute(
                    from: isset($params['from']) ? (string) $params['from'] : null,
                    title: $params['title'] ?? null
                );

                $outcome = [
                    'message' => $delivery['message'],
                    'result' => $delivery['result'],
                    'context' => $context,
                ];

                return $delivery['delivered'] ? $outcome : $this->failWorkflow($outcome + ['entity' => null]);
            },
            company: $channel->company,
            additionalParams: $params,
        );
    }
}
