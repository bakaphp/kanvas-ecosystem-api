<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Triggers\Actions;

use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Social\Messages\Actions\CreateMessageAction;
use Kanvas\Social\Messages\DataTransferObject\MessageInput;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Actions\CreateMessageTypeAction;
use Kanvas\Social\MessagesTypes\DataTransferObject\MessageTypeInput;
use Kanvas\Social\MessagesTypes\Models\MessageType;
use Kanvas\Users\Models\Users;

class PostHandOffNoteAction
{
    public function __construct(
        protected Lead $lead,
        protected Users $agent,
        protected string $channel,
        protected array $previousState,
        protected array $currentState
    ) {
    }

    public function execute(): ?Message
    {
        $notesChannel = $this->lead->notes;

        if (! $notesChannel) {
            return null;
        }

        $messageType = $this->getOrCreateNoteMessageType();
        $noteContent = $this->buildTriggerNoteMessage();

        $messageInput = new MessageInput(
            app: $this->lead->app,
            company: $this->lead->company,
            user: $this->agent,
            type: $messageType,
            message: [
                'content' => $noteContent,
                'from_me' => true,
            ],
            categories: ['ai-agent', 'trigger'],
        );

        $createMessageAction = new CreateMessageAction($messageInput);
        $createMessageAction->runWorkflow = true;
        $message = $createMessageAction->execute();

        $notesChannel->addMessage($message, $this->agent);

        return $message;
    }

    protected function getOrCreateNoteMessageType(): MessageType
    {
        $messageTypeInput = new MessageTypeInput(
            apps_id: $this->lead->app->getId(),
            languages_id: 1,
            name: 'Note',
            verb: 'note',
            template: '{{message}}',
            templates_plura: '{{message}}',
        );

        return new CreateMessageTypeAction($messageTypeInput)->execute();
    }

    protected function buildTriggerNoteMessage(): string
    {
        $leadName = $this->lead->people->name ?? 'Unknown Lead';
        $agentName = $this->agent->displayname;
        $channelName = ucfirst($this->channel);

        $previousAiMode = $this->previousState['ai_mode'] ?? 'N/A';
        $currentAiMode = $this->currentState['ai_mode'] ?? 'N/A';

        $previousFollowUp = $this->previousState['ai_follow_up'] ?? 'N/A';
        $currentFollowUp = $this->currentState['ai_follow_up'] ?? 'N/A';

        return <<<NOTE
        Trigger State Change Notification

        Lead: {$leadName}
        Channel: {$channelName}
        Agent: {$agentName}

        State Changes:
        AI Mode: {$previousAiMode} → {$currentAiMode}
        Follow Up: {$previousFollowUp} → {$currentFollowUp}
        NOTE;
    }
}
