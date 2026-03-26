<?php

declare(strict_types=1);

namespace Kanvas\Connectors\VoiceBridge\Actions;

use Kanvas\Connectors\Twilio\Client as TwilioClient;
use Kanvas\Guild\Leads\Models\Lead;
use Throwable;

class FetchCallRecordingAction
{
    public function __construct(
        protected readonly Lead $lead,
        protected readonly string $callSid,
    ) {
    }

    /**
     * @return array<string>
     */
    public function execute(): array
    {
        try {
            $twilio = TwilioClient::getInstanceByCompany($this->lead->company);
            $recordings = $twilio->recordings->read(['callSid' => $this->callSid]);

            return array_map(
                fn ($recording) => 'https://api.twilio.com' . str_replace('.json', '.mp3', $recording->uri),
                $recordings
            );
        } catch (Throwable) {
            return [];
        }
    }
}
