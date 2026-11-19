<?php

declare(strict_types=1);

namespace Kanvas\Meeting\Notes\Jobs;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Meeting\Notes\Actions\GenerateMeetingNotesAction;
use Kanvas\Meeting\Transcripts\Models\MeetingTranscript;

class GenerateMeetingNotesJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use KanvasJobsTrait;
    use Queueable;
    use SerializesModels;

    public const string QUEUE = 'meeting-notes';

    /**
     * Safe to repeat: the note is rewritten in place, so a retry after a provider error costs one
     * more call and nothing else.
     */
    public int $tries = 3;

    public int $backoff = 60;

    public int $timeout = 600;

    public function __construct(
        public readonly MeetingTranscript $transcript,
    ) {
        $this->onQueue(self::QUEUE);
    }

    public function handle(): void
    {
        $this->overwriteAppService($this->transcript->meeting->app);

        try {
            new GenerateMeetingNotesAction($this->transcript)->execute();
        } catch (ValidationException $e) {
            // Nothing a retry changes: no provider key, an empty answer.
            $this->fail($e);
        }
    }
}
