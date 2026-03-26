<?php

declare(strict_types=1);

namespace Kanvas\Connectors\VoiceBridge\Actions;

use Kanvas\Connectors\VoiceBridge\Enums\CustomFieldEnum;
use Illuminate\Support\Facades\Log;
use Kanvas\Connectors\VoiceBridge\Jobs\SaveCallRecordingJob;
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
        protected readonly array $transcript,
        protected readonly string $sessionId,
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
        $callSid = (string) $this->lead->get(CustomFieldEnum::CALL_SID->value);

        $message = new CreateMessageAction(
            new MessageInput(
                app: $app,
                company: $this->lead->company,
                user: $this->lead->user,
                type: $messageType,
                message: [
                    'transcript' => $this->transcript,
                    'session_id' => $this->sessionId,
                    'call_sid' => $callSid,
                    'recordings' => [],
                ],
                is_public: 1,
            ),
        )->execute();

        $message->addEntity($this->lead);


        if (! empty($callSid)) {
            SaveCallRecordingJob::dispatch($this->lead, $message, $callSid)
                ->delay(now()->addMinutes(5));
        }

        return $message;
    }
}
