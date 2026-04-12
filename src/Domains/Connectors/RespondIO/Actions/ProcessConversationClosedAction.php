<?php

declare(strict_types=1);

namespace Kanvas\Connectors\RespondIO\Actions;

use Kanvas\Connectors\RespondIO\BaseRespondIOAction;
use Kanvas\Connectors\RespondIO\Traits\HasChannelProcessing;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Enums\ConfigurationEnum;
use Kanvas\Intelligence\Triggers\Enums\TriggersEnum;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Override;

class ProcessConversationClosedAction extends BaseRespondIOAction
{
    use HasChannelProcessing;

    #[Override]
    public function execute(): array
    {
        /** @var array<string, mixed> $contact */
        $contact = $this->payload['contact'] ?? [];
        /** @var array<string, mixed> $conversation */
        $conversation = $this->payload['conversation'] ?? [];

        $identifier = $this->getContactIdentifier($contact);

        if ($identifier === null) {
            return ['error' => 'Missing contact identifier'];
        }

        $channel = $this->findChannelByIdentifier($this->app, $this->company, $identifier);

        if (! $channel) {
            return ['status' => 'no_channel_found', 'identifier' => $identifier];
        }

        $lead = $this->findLeadFromChannel($this->app, $this->company, $channel);

        if ($lead instanceof Lead) {
            $lead->set(ConfigurationEnum::AGENT_HAND_OFF->value, 1);
            $lead->set(ConfigurationEnum::AGENT_HAND_OFF_TYPE->value, 'conversation_closed');

            $lead->fireWorkflow(
                WorkflowEnum::TRIGGER_AI->value,
                true,
                [
                    'app' => $this->app,
                    'trigger_type' => TriggersEnum::HUMAN_TAKEOVER->value,
                ]
            );
        }

        return [
            'status' => 'conversation_closed',
            'channel_id' => $channel->getId(),
            'lead_id' => $lead?->getId(),
            'identifier' => $identifier,
            'summary' => $conversation['summary'] ?? null,
            'category' => $conversation['category'] ?? null,
        ];
    }
}
