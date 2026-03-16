<?php

declare(strict_types=1);

namespace Kanvas\Connectors\VoiceBridge\Actions;

use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Social\Messages\Models\Message;

class SendVoiceMessageAction
{
    public function __construct(
        protected readonly Message $message,
    ) {
    }

    public function execute(): array
    {
        $lead = $this->message->entity();

        if (! $lead instanceof Lead) {
            return [];
        }

        $app = $lead->app;

        $messageContent = is_array($this->message->message)
            ? ($this->message->message['content'] ?? $this->message->message['raw'] ?? '')
            : (string) $this->message->message;

        $agent = Agent::fromApp($app)
            ->fromCompany($lead->company)
            ->where('name', 'voiceOutreachAgent')
            ->firstOrFail();

        InitVoiceSessionAction::fromLead($lead, $agent, $messageContent)->execute();

        return TriggerVoiceCallAction::fromLead($lead)->execute();
    }
}
