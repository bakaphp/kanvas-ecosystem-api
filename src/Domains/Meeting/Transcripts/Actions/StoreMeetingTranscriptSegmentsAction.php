<?php

declare(strict_types=1);

namespace Kanvas\Meeting\Transcripts\Actions;

use Baka\Support\Str;
use Illuminate\Support\Facades\DB;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Meeting\Transcripts\Enums\MeetingTranscriptStatusEnum;
use Kanvas\Meeting\Transcripts\Models\MeetingTranscript;
use Kanvas\Meeting\Transcripts\Models\MeetingTranscriptSegment;
use Kanvas\Meeting\Transcripts\Models\MeetingTranscriptSpeaker;

class StoreMeetingTranscriptSegmentsAction
{
    protected const string DEFAULT_SOURCE_KEY = 'full';
    protected const int INSERT_CHUNK = 500;

    /**
     * @param array<int, array{text: string, speaker_label?: string|null, source_key?: string|null, start_ms?: int|null, end_ms?: int|null, channel?: int|null}> $segments in transcript order
     */
    public function __construct(
        protected readonly MeetingTranscript $transcript,
        protected readonly array $segments,
    ) {
    }

    /**
     * Replaces the transcript's segments. Speakers are matched by (source_key, provider_label) and kept
     * across runs, so a name the user already confirmed survives a re-store.
     */
    public function execute(): MeetingTranscript
    {
        if ($this->segments === []) {
            throw new ValidationException('A transcript needs at least one segment');
        }

        return DB::connection('meeting')->transaction(function (): MeetingTranscript {
            $this->transcript->segments()->delete();

            $speakerIds = [];
            $rows = [];

            foreach (array_values($this->segments) as $position => $segment) {
                $label = Str::trimToNull($segment['speaker_label'] ?? null);
                $sourceKey = Str::trimToNull($segment['source_key'] ?? null) ?? self::DEFAULT_SOURCE_KEY;

                if (isset($segment['start_ms'], $segment['end_ms']) && $segment['end_ms'] < $segment['start_ms']) {
                    throw new ValidationException('Segment ' . $position . ' ends before it starts');
                }

                $rows[] = [
                    'meeting_transcripts_id' => $this->transcript->getId(),
                    'meeting_transcript_speakers_id' => $label !== null
                        ? $speakerIds[$sourceKey . '|' . $label] ??= $this->speakerId($sourceKey, $label)
                        : null,
                    'position' => $position,
                    'start_ms' => isset($segment['start_ms']) ? (int) $segment['start_ms'] : null,
                    'end_ms' => isset($segment['end_ms']) ? (int) $segment['end_ms'] : null,
                    'channel' => $segment['channel'] ?? null,
                    'text' => (string) $segment['text'],
                ];
            }

            foreach (array_chunk($rows, self::INSERT_CHUNK) as $chunk) {
                MeetingTranscriptSegment::query()->insert($chunk);
            }

            return new UpdateMeetingTranscriptStatusAction(
                $this->transcript,
                MeetingTranscriptStatusEnum::COMPLETED
            )->execute();
        });
    }

    protected function speakerId(string $sourceKey, string $label): int
    {
        return MeetingTranscriptSpeaker::query()->firstOrCreate([
            'meeting_transcripts_id' => $this->transcript->getId(),
            'source_key' => $sourceKey,
            'provider_label' => $label,
        ])->getId();
    }
}
