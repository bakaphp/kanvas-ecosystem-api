<?php

declare(strict_types=1);

namespace App\Console\Commands\Meeting;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Meeting\Meetings\Models\Meeting;
use Kanvas\Meeting\Transcripts\Actions\TranscribeMeetingAction;
use Kanvas\Meeting\Transcripts\Enums\MeetingTranscriptStatusEnum;

/**
 * Transcribes one meeting's recording again, as a new transcript version. For a run that failed, and
 * for a long recording on an environment without a queue worker.
 */
class TranscribeMeetingCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas:meeting:transcribe {meeting_id : the meeting whose recording to transcribe}';

    protected $description = 'Transcribe a meeting recording into a new transcript version.';

    public function handle(): int
    {
        /** @var Meeting $meeting */
        $meeting = Meeting::query()->findOrFail((int) $this->argument('meeting_id'));
        $this->overwriteAppService($meeting->app);

        $transcript = new TranscribeMeetingAction($meeting)->execute();

        $this->line(sprintf(
            'Meeting %d, transcript v%d on %s: %s%s, %d segments.',
            $meeting->getId(),
            $transcript->version,
            $transcript->model,
            $transcript->status->value,
            $transcript->error_code !== null ? ' (' . $transcript->error_code . ')' : '',
            $transcript->segments()->count(),
        ));

        return $transcript->status === MeetingTranscriptStatusEnum::COMPLETED ? self::SUCCESS : self::FAILURE;
    }
}
