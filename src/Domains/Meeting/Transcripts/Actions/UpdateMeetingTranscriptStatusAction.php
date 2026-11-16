<?php

declare(strict_types=1);

namespace Kanvas\Meeting\Transcripts\Actions;

use Illuminate\Support\Facades\DB;
use Kanvas\Meeting\Meetings\Enums\MeetingStatusEnum;
use Kanvas\Meeting\Transcripts\Enums\MeetingTranscriptStatusEnum;
use Kanvas\Meeting\Transcripts\Models\MeetingTranscript;

class UpdateMeetingTranscriptStatusAction
{
    public function __construct(
        protected readonly MeetingTranscript $transcript,
        protected readonly MeetingTranscriptStatusEnum $status,
        protected readonly ?string $errorCode = null,
        protected readonly ?string $providerJobId = null,
    ) {
    }

    public function execute(): MeetingTranscript
    {
        return DB::connection('meeting')->transaction(function (): MeetingTranscript {
            $this->transcript->status = $this->status;
            $this->transcript->error_code = $this->status === MeetingTranscriptStatusEnum::FAILED ? $this->errorCode : null;
            $this->transcript->provider_job_id = $this->providerJobId ?? $this->transcript->provider_job_id;
            $this->transcript->completed_at = $this->status === MeetingTranscriptStatusEnum::COMPLETED ? now() : null;
            $this->transcript->saveOrFail();

            $meeting = $this->transcript->meeting;

            // A late answer for a superseded run must not overwrite what the newest version says.
            if ((int) $meeting->transcripts()->max('version') !== $this->transcript->version) {
                return $this->transcript;
            }

            $meetingStatus = match ($this->status) {
                MeetingTranscriptStatusEnum::COMPLETED => MeetingStatusEnum::COMPLETED,
                MeetingTranscriptStatusEnum::FAILED => MeetingStatusEnum::FAILED,
                default => MeetingStatusEnum::TRANSCRIBING,
            };

            $meeting->status = $meetingStatus;
            $meeting->saveOrFail();

            return $this->transcript;
        });
    }
}
