<?php

declare(strict_types=1);

namespace Kanvas\Connectors\VoiceBridge\Actions;

use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Social\Messages\Actions\CreateMessageAction;
use Kanvas\Social\Messages\DataTransferObject\MessageInput;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Actions\CreateMessageTypeAction;
use Kanvas\Social\MessagesTypes\DataTransferObject\MessageTypeInput;

class SaveVoiceTranscriptAction
{
    public function __construct(
        protected readonly Lead $lead,
        protected readonly Apps $app,
        protected readonly array $transcript,
        protected readonly string $sessionId,
    ) {
    }

    public function execute(): Message
    {
        $messageType = new CreateMessageTypeAction(
            new MessageTypeInput(
                apps_id: $this->app->getId(),
                name: 'Voice Transcript',
                verb: 'voice-transcript',
            )
        )->execute();

        $message = new CreateMessageAction(
            new MessageInput(
                app: $this->app,
                company: $this->lead->company,
                user: $this->lead->user,
                type: $messageType,
                message: [
                    'transcript' => $this->transcript,
                    'session_id' => $this->sessionId,
                ],
                is_public: 1,
            ),
        )->execute();

        $message->addEntity($this->lead);

        return $message;
    }
}
