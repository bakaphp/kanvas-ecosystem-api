<?php

declare(strict_types=1);

namespace Kanvas\HumanResources\Meetings\Recordings\Actions;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Kanvas\Exceptions\ValidationException;
use Kanvas\HumanResources\Meetings\Models\Meeting;
use Kanvas\HumanResources\Meetings\Recordings\DataTransferObject\MeetingRecording as MeetingRecordingData;
use Kanvas\HumanResources\Meetings\Recordings\Models\MeetingRecording;
use Kanvas\HumanResources\Meetings\Transcripts\Jobs\TranscribeMeetingJob;
use Throwable;

class UploadMeetingRecordingAction
{
    public function __construct(
        protected readonly Meeting $meeting,
        protected readonly UploadedFile $file,
        protected readonly array $input,
    ) {
    }

    /**
     * Stores the audio on the default disk under a key the server mints, then attaches it. The client
     * sends the sha256 of the file it recorded: a retry of the same file is answered with the stored
     * recording without storing it again, and bytes that do not hash to it are refused. A new recording
     * is queued for transcription.
     */
    public function execute(): MeetingRecording
    {
        $sha256 = strtolower((string) $this->input['sha256']);
        $existing = $this->meeting->recording()->first();

        if ($existing !== null && $existing->sha256 === $sha256) {
            // The retry is also the way back for a recording whose job was never queued or was lost.
            if (! $this->meeting->transcripts()->exists()) {
                TranscribeMeetingJob::dispatchFor($this->meeting);
            }

            return $existing;
        }

        if ($existing !== null) {
            throw new ValidationException('Meeting ' . $this->meeting->getId() . ' already has a recording');
        }

        if (hash_file('sha256', $this->file->getRealPath()) !== $sha256) {
            throw new ValidationException('The uploaded recording does not match its sha256');
        }

        $disk = (string) config('filesystems.default');
        $storageKey = Storage::disk($disk)->putFileAs(
            'meetings/' . $this->meeting->apps_id . '/' . $this->meeting->companies_id . '/' . $this->meeting->uuid,
            $this->file,
            $sha256 . '.webm'
        );

        if ($storageKey === false) {
            throw new ValidationException('The recording could not be stored');
        }

        try {
            $recording = new AttachMeetingRecordingAction(
                MeetingRecordingData::from($this->meeting, [
                    ...$this->input,
                    'storage_disk' => $disk,
                    'storage_key' => $storageKey,
                    'size_bytes' => $this->file->getSize(),
                    'sha256' => $sha256,
                ]),
            )->execute();
        } catch (Throwable $e) {
            // A concurrent upload of another file won the meeting: this copy belongs to no row. The
            // same file from a concurrent request shares the key, so it is kept when a row holds it.
            if (! $this->meeting->recording()->where('storage_key', $storageKey)->exists()) {
                Storage::disk($disk)->delete($storageKey);
            }

            throw $e;
        }

        // A concurrent upload of the same file is answered with the row the other request created.
        if ($recording->wasRecentlyCreated) {
            TranscribeMeetingJob::dispatchFor($this->meeting);
        }

        return $recording;
    }
}
