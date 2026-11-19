<?php

declare(strict_types=1);

namespace Kanvas\Meeting\Recordings\Actions;

use Illuminate\Support\Facades\DB;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Meeting\Meetings\Enums\MeetingStatusEnum;
use Kanvas\Meeting\Recordings\DataTransferObject\MeetingRecording as MeetingRecordingData;
use Kanvas\Meeting\Recordings\Models\MeetingRecording;

class AttachMeetingRecordingAction
{
    public function __construct(
        protected readonly MeetingRecordingData $data,
    ) {
    }

    /**
     * A meeting holds one recording. Confirming the same file twice (same sha256) is a client retry and
     * answers with the stored row; a different file is refused.
     */
    public function execute(): MeetingRecording
    {
        if (! array_key_exists($this->data->storageDisk, config('filesystems.disks'))) {
            throw new ValidationException('Unknown storage disk ' . $this->data->storageDisk);
        }

        return DB::connection('meeting')->transaction(function (): MeetingRecording {
            $meeting = $this->data->meeting->lockForWrite();
            $existing = $meeting->recording()->first();

            if ($existing !== null) {
                return $existing->sha256 === $this->data->sha256
                    ? $existing
                    : throw new ValidationException('Meeting ' . $meeting->getId() . ' already has a recording');
            }

            $recording = new MeetingRecording();
            $recording->meetings_id = $meeting->getId();
            $recording->storage_disk = $this->data->storageDisk;
            $recording->storage_key = $this->data->storageKey;
            $recording->mime_type = $this->data->mimeType;
            $recording->size_bytes = $this->data->sizeBytes;
            $recording->sha256 = $this->data->sha256;
            $recording->duration_ms = $this->data->durationMs;
            $recording->channel_map = $this->data->channelMap;
            $recording->uploaded_at = now();
            $recording->saveOrFail();

            if ($meeting->status === MeetingStatusEnum::AWAITING_UPLOAD) {
                $meeting->status = MeetingStatusEnum::UPLOADED;
                $meeting->saveOrFail();
            }

            return $recording;
        });
    }
}
