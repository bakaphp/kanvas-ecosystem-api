<?php

declare(strict_types=1);

namespace Kanvas\HumanResources\Meetings\Transcripts\Jobs;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Kanvas\HumanResources\Meetings\Models\Meeting;
use Kanvas\HumanResources\Meetings\Transcripts\Actions\TranscribeMeetingAction;
use Kanvas\HumanResources\Meetings\Transcripts\Actions\UpdateMeetingTranscriptStatusAction;
use Kanvas\HumanResources\Meetings\Transcripts\Enums\MeetingTranscriptStatusEnum;
use Throwable;

class TranscribeMeetingJob implements ShouldQueue, ShouldBeUniqueUntilProcessing
{
    use Dispatchable;
    use InteractsWithQueue;
    use KanvasJobsTrait;
    use Queueable;
    use SerializesModels;

    public const string QUEUE = 'meeting-transcripts';

    /**
     * One attempt: every run writes a new transcript version, and a failed run is already stored as
     * a failed transcript by the action.
     */
    public int $tries = 1;

    public int $timeout = 3600;

    /**
     * How long a queued job keeps a second one for the same meeting out. Two requests can both ask
     * for it (a concurrent upload of the same file, a retry while the first is still queued).
     */
    public int $uniqueFor = 3600;

    public function __construct(
        public readonly Meeting $meeting,
    ) {
        $this->onQueue(self::QUEUE);
    }

    public function handle(): void
    {
        $this->overwriteAppService($this->meeting->app);

        new TranscribeMeetingAction($this->meeting)->execute();
    }

    public function uniqueId(): string
    {
        return (string) $this->meeting->getId();
    }

    /**
     * The action stores its own failures; this is for the run that never got to: a worker killed
     * mid-transcription (deploy, OOM, timeout) is redelivered and failed by the queue without running,
     * which would leave the transcript `processing` and the meeting `transcribing` for good.
     */
    public function failed(?Throwable $exception): void
    {
        $transcript = $this->meeting->latestTranscript()->first();
        $unfinished = [MeetingTranscriptStatusEnum::PENDING, MeetingTranscriptStatusEnum::PROCESSING];

        if ($transcript !== null && in_array($transcript->status, $unfinished, true)) {
            new UpdateMeetingTranscriptStatusAction(
                $transcript,
                MeetingTranscriptStatusEnum::FAILED,
                TranscribeMeetingAction::ERROR_INTERRUPTED,
            )->execute();
        }
    }

    /**
     * On the `sync` connection (local) a plain dispatch would transcribe inside the upload request and
     * hold the client's "uploading" state for minutes, so there it runs once the response is sent.
     */
    public static function dispatchFor(Meeting $meeting): void
    {
        $pending = self::dispatch($meeting);

        if (config('queue.default') === 'sync') {
            $pending->afterResponse();
        }
    }
}
