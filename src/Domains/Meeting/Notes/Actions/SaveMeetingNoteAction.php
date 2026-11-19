<?php

declare(strict_types=1);

namespace Kanvas\Meeting\Notes\Actions;

use Illuminate\Support\Facades\DB;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Meeting\Notes\DataTransferObject\MeetingNote as MeetingNoteData;
use Kanvas\Meeting\Notes\Enums\MeetingNoteKindEnum;
use Kanvas\Meeting\Notes\Models\MeetingNote;

class SaveMeetingNoteAction
{
    public function __construct(
        protected readonly MeetingNoteData $data,
    ) {
    }

    /**
     * A meeting keeps one note per kind, so saving again rewrites it.
     */
    public function execute(): MeetingNote
    {
        $transcript = $this->data->transcript;

        if ($this->data->kind === MeetingNoteKindEnum::ENHANCED && $transcript === null) {
            throw new ValidationException('An enhanced note needs the transcript it was written from');
        }

        if ($this->data->kind === MeetingNoteKindEnum::MANUAL && $transcript !== null) {
            throw new ValidationException('A manual note is not tied to a transcript');
        }

        if ($transcript !== null && $transcript->meetings_id !== $this->data->meeting->getId()) {
            throw new ValidationException('The transcript does not belong to this meeting');
        }

        return DB::connection('meeting')->transaction(function () use ($transcript): MeetingNote {
            $meeting = $this->data->meeting->lockForWrite();

            return MeetingNote::query()->updateOrCreate(
                [
                    'meetings_id' => $meeting->getId(),
                    'kind' => $this->data->kind->value,
                ],
                [
                    'meeting_transcripts_id' => $transcript?->getId(),
                    'body' => $this->data->body,
                ]
            );
        });
    }
}
