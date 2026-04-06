<?php

declare(strict_types=1);

namespace Kanvas\Connectors\VoiceBridge\Actions;

use Kanvas\Filesystem\Actions\AttachFilesystemAction;
use Kanvas\Filesystem\Services\FilesystemServices;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Social\Messages\Actions\CreateMessageAction;
use Kanvas\Social\Messages\DataTransferObject\MessageInput;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Actions\CreateMessageTypeAction;
use Kanvas\Social\MessagesTypes\DataTransferObject\MessageTypeInput;
use Kanvas\Users\Models\Users;

class SaveVoiceTranscriptAction
{
    public function __construct(
        protected readonly Lead $lead,
        protected readonly array $transcript,
        protected readonly string $sessionId,
        protected readonly ?string $recordingUrl = null,
    ) {
    }

    public function execute(): Message
    {
        $app = $this->lead->app;

        $messageType = new CreateMessageTypeAction(
            new MessageTypeInput(
                apps_id: $app->getId(),
                name: 'Voice Transcript',
                verb: 'voice-transcript',
            )
        )->execute();

        $this->lead->refresh();

        $message = new CreateMessageAction(
            new MessageInput(
                app: $app,
                company: $this->lead->company,
                user: $this->lead->user,
                type: $messageType,
                message: [
                    'transcript' => $this->transcript,
                    'session_id' => $this->sessionId,
                    'recording_url' => $this->recordingUrl,
                ],
                is_public: 1,
            ),
        )->execute();

        $message->addEntity($this->lead);

        if (! empty($this->recordingUrl)) {
            $user = Users::getById($this->lead->users_id);
            $filesystem = new FilesystemServices($app);
            $file = $filesystem->uploadFileFromUrl($this->recordingUrl, $user);
            new AttachFilesystemAction($file, $message)->execute('voice-recording');
        }

        return $message;
    }
}
