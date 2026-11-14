<?php

declare(strict_types=1);

namespace Kanvas\Connectors\RespondIO\Actions;

use Kanvas\Connectors\RespondIO\BaseRespondIOAction;
use Kanvas\Intelligence\Enums\ConfigurationEnum;
use Kanvas\Intelligence\Triggers\Enums\TriggersEnum;
use Kanvas\Intelligence\Triggers\Events\LeadAiTriggeredEvent;
use Override;

class ProcessConversationClosedAction extends BaseRespondIOAction
{
    #[Override]
    public function execute(): array
    {
        /** @var array<string, mixed> $conversation */
        $conversation = $this->payload['conversation'] ?? [];
        $channel = $this->resolveChannel();

        if ($channel === null) {
            return $this->unresolved('no_channel_found');
        }

        $lead = $this->resolveLead($channel);

        if ($lead !== null) {
            $lead->set(ConfigurationEnum::AGENT_HAND_OFF->value, 1);
            $lead->set(ConfigurationEnum::AGENT_HAND_OFF_TYPE->value, 'conversation_closed');

            LeadAiTriggeredEvent::announce($lead, TriggersEnum::HUMAN_TAKEOVER->value);
        }

        return [
            'status' => 'conversation_closed',
            'channel_id' => $channel->getId(),
            'lead_id' => $lead?->getId(),
            'identifier' => $this->contactIdentifier(),
            'summary' => $conversation['summary'] ?? null,
            'category' => $conversation['category'] ?? null,
        ];
    }
}
