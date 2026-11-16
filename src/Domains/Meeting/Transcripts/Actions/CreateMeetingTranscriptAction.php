<?php

declare(strict_types=1);

namespace Kanvas\Meeting\Transcripts\Actions;

use Illuminate\Support\Facades\DB;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Meeting\Meetings\Enums\MeetingStatusEnum;
use Kanvas\Meeting\Transcripts\DataTransferObject\MeetingTranscript as MeetingTranscriptData;
use Kanvas\Meeting\Transcripts\Models\MeetingTranscript;

class CreateMeetingTranscriptAction
{
    public function __construct(
        protected readonly MeetingTranscriptData $data,
    ) {
    }

    public function execute(): MeetingTranscript
    {
        return DB::connection('meeting')->transaction(function (): MeetingTranscript {
            $meeting = $this->data->meeting->lockForWrite();

            if (! $meeting->recording()->exists()) {
                throw new ValidationException('Meeting ' . $meeting->getId() . ' has no recording to transcribe');
            }

            $transcript = new MeetingTranscript();
            $transcript->meetings_id = $meeting->getId();
            $transcript->version = (int) $meeting->transcripts()->max('version') + 1;
            $transcript->provider = $this->data->provider;
            $transcript->model = $this->data->model;
            $transcript->provider_job_id = $this->data->providerJobId;
            $transcript->saveOrFail();

            $meeting->status = MeetingStatusEnum::TRANSCRIBING;
            $meeting->saveOrFail();

            return $transcript->refresh();
        });
    }
}
