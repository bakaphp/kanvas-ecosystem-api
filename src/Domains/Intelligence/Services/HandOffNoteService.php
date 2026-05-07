<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Services;

use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Social\Messages\Actions\CreateMessageAction;
use Kanvas\Social\Messages\DataTransferObject\MessageInput;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Actions\CreateMessageTypeAction;
use Kanvas\Social\MessagesTypes\DataTransferObject\MessageTypeInput;
use Kanvas\Social\MessagesTypes\Models\MessageType;
use Kanvas\Users\Models\Users;

class HandOffNoteService
{
    public function __construct(
        protected Lead $lead,
        protected Users $agent
    ) {
    }

    public function postHandOffNote(string $conversationSummary, string $handoffType = 'human'): ?Message
    {
        $notesChannel = $this->lead->notes;

        if (! $notesChannel) {
            return null;
        }

        $messageType = $this->getOrCreateNoteMessageType();
        $noteContent = $this->buildHandOffMessage($conversationSummary, $handoffType);

        $messageInput = new MessageInput(
            app: $this->lead->app,
            company: $this->lead->company,
            user: $this->agent,
            type: $messageType,
            message: [
                'content' => $noteContent,
                'from_me' => true,
            ],
            categories: ['ai-agent'],
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

    protected function buildHandOffMessage(string $conversationSummary, string $handoffType): string
    {
        $leadName = $this->lead->people->name ?? 'Unknown Lead';
        $agentName = $this->agent->displayname;

        $handoffTypeLabel = match (strtolower($handoffType)) {
            'compliance_internal' => 'Compliance Handoff',
            'human' => 'Human Agent Handoff',
            default => 'Agent Handoff',
        };

        return <<<NOTE
        {$handoffTypeLabel}

        Lead: {$leadName}
        Assigned to: {$agentName}

        Conversation Summary:
        {$conversationSummary}
        NOTE;
    }
}
