<?php

declare(strict_types=1);

namespace Kanvas\Meeting\Transcripts\Actions;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Meeting\Meetings\Models\Meeting;
use Kanvas\Meeting\Notes\Jobs\GenerateMeetingNotesJob;
use Kanvas\Meeting\Recordings\Models\MeetingRecording;
use Kanvas\Meeting\Transcripts\Agents\TranscriptAgent;
use Kanvas\Meeting\Transcripts\DataTransferObject\MeetingTranscript as MeetingTranscriptData;
use Kanvas\Meeting\Transcripts\Enums\MeetingTranscriptStatusEnum;
use Kanvas\Meeting\Transcripts\Exceptions\TranscriptProviderException;
use Kanvas\Meeting\Transcripts\Models\MeetingTranscript;
use Kanvas\Meeting\Transcripts\Services\RecordingAudioService;
use Kanvas\Meeting\Transcripts\Services\TranscriptAgentService;
use Throwable;

class TranscribeMeetingAction
{
    public const string ERROR_NO_SPEECH = 'no_speech';
    public const string ERROR_FAILED = 'transcription_failed';
    public const string ERROR_INTERRUPTED = 'transcription_interrupted';

    /**
     * @param TranscriptAgent|null $handler a ready agent, so a test runs without a live provider
     */
    public function __construct(
        protected readonly Meeting $meeting,
        protected readonly ?TranscriptAgent $handler = null,
    ) {
    }

    /**
     * Writes a new transcript version for the meeting's recording. Every outcome is a transcript row:
     * a run that cannot start or breaks is stored as failed with its error code, so the meeting never
     * stays in `transcribing` and the client has something to show. A completed one queues the notes.
     */
    public function execute(): MeetingTranscript
    {
        $recording = $this->meeting->recording()->first();

        if ($recording === null) {
            throw new ValidationException('Meeting ' . $this->meeting->getId() . ' has no recording to transcribe');
        }

        try {
            $handler = $this->handler ?? new TranscriptAgentService()->handlerFor($this->meeting);
        } catch (TranscriptProviderException $e) {
            report($e);

            return $this->fail($this->createTranscript($e->provider, $e->model), $e->errorCode);
        }

        $transcript = $this->createTranscript($handler->providerName(), $handler->resolvedModelName());

        try {
            new UpdateMeetingTranscriptStatusAction($transcript, MeetingTranscriptStatusEnum::PROCESSING)->execute();
            $segments = $this->segments($recording, $handler, $transcript);

            if ($segments === []) {
                return $this->fail($transcript, self::ERROR_NO_SPEECH);
            }

            $transcript = new StoreMeetingTranscriptSegmentsAction($transcript, $segments)->execute();
        } catch (Throwable $e) {
            report($e);

            return $this->fail($transcript, self::ERROR_FAILED);
        }

        // Outside the try: a note that cannot be written must not turn a good transcript into a failed one.
        GenerateMeetingNotesJob::dispatch($transcript);

        return $transcript;
    }

    /**
     * Speaker labels are only consistent inside one request, so each chunk is its own `source_key`:
     * "spk:0" of the second half hour is not assumed to be "spk:0" of the first.
     *
     * @return list<array{text: string, speaker_label: ?string, source_key: string, start_ms: ?int, end_ms: ?int}>
     */
    protected function segments(MeetingRecording $recording, TranscriptAgent $handler, MeetingTranscript $transcript): array
    {
        $workDir = storage_path('app/meeting-transcripts/' . Str::uuid());

        try {
            $chunks = new RecordingAudioService()->chunks($recording, $workDir);
            $segments = [];

            foreach ($chunks as $index => $chunk) {
                $heard = $handler->transcribe(
                    $chunk['path'],
                    RecordingAudioService::CHUNK_MIME_TYPE,
                    'meeting-transcript-' . $transcript->getId() . '-' . $index,
                );

                foreach ($heard as $segment) {
                    $segments[] = [
                        ...$segment,
                        'source_key' => count($chunks) === 1 ? 'full' : 'part-' . ($index + 1),
                        'start_ms' => $segment['start_ms'] !== null ? $segment['start_ms'] + $chunk['offset_ms'] : null,
                        'end_ms' => $segment['end_ms'] !== null ? $segment['end_ms'] + $chunk['offset_ms'] : null,
                    ];
                }
            }

            return $segments;
        } finally {
            File::deleteDirectory($workDir);
        }
    }

    protected function createTranscript(string $provider, string $model): MeetingTranscript
    {
        return new CreateMeetingTranscriptAction(
            new MeetingTranscriptData($this->meeting, mb_substr($provider, 0, 64), mb_substr($model, 0, 128)),
        )->execute();
    }

    protected function fail(MeetingTranscript $transcript, string $errorCode): MeetingTranscript
    {
        return new UpdateMeetingTranscriptStatusAction(
            $transcript,
            MeetingTranscriptStatusEnum::FAILED,
            $errorCode,
        )->execute();
    }
}
