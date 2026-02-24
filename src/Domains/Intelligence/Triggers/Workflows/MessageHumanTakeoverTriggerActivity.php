<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Triggers\Workflows;

use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Support\UnrespondedAgentMessageCache;
use Kanvas\Intelligence\Triggers\Enums\TriggersEnum;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Kanvas\Workflow\KanvasActivity;

/**
 * Activity that triggers a human takeover workflow when a message
 * has from_human flag set to true and the entity is a Lead.
 */
class MessageHumanTakeoverTriggerActivity extends KanvasActivity
{
    public $tries = 3;

    public function execute(Message $message, Apps $app, array $params): array
    {
        $this->overwriteAppService($app);

        if (! $message instanceof Message) {
            return $this->failWorkflow([
                'message' => 'Message not found',
                'entity' => null,
            ]);
        }

        return $this->executeIntegration(
            entity: $message,
            app: $app,
            integration: IntegrationsEnum::INTERNAL,
            integrationOperation: function ($message, $app, $integrationCompany, $additionalParams) use ($params) {
                $messageData = $message->message;
                $fromHuman = $messageData['from_human'] ?? false;

                if (empty($fromHuman)) {
                    return [
                        'message' => 'Message is not from human',
                        'entity' => null,
                    ];
                }

                $lead = $message->entity();

                if (! $lead instanceof Lead) {
                    return [
                        'message' => 'Message entity is not a Lead',
                        'entity' => null,
                    ];
                }

                $channel = $message->channels()->first();
                if ($channel && $channel->name != 'Notes') {
                    UnrespondedAgentMessageCache::clear($lead, $channel);
                }

                $lead->fireWorkflow(
                    WorkflowEnum::TRIGGER_AI->value,
                    true,
                    [
                        'app' => $app,
                        'trigger_type' => TriggersEnum::HUMAN_TAKEOVER->value,
                    ]
                );

                return [
                    'message' => 'Human takeover trigger fired successfully',
                    'entity' => $lead,
                    'lead_id' => $lead->getId(),
                ];
            },
            company: $message->company,
        );
    }
}
