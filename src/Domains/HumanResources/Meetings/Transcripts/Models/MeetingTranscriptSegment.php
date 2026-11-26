<?php

declare(strict_types=1);

namespace Kanvas\HumanResources\Meetings\Transcripts\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\HumanResources\Meetings\Models\BaseModel;

/**
 * @property int $id
 * @property int $meeting_transcripts_id
 * @property int|null $meeting_transcript_speakers_id
 * @property int $position
 * @property int|null $start_ms
 * @property int|null $end_ms
 * @property int|null $channel
 * @property string $text
 */
class MeetingTranscriptSegment extends BaseModel
{
    protected $table = 'meeting_transcript_segments';

    public $timestamps = false;

    public function transcript(): BelongsTo
    {
        return $this->belongsTo(MeetingTranscript::class, 'meeting_transcripts_id');
    }

    public function speaker(): BelongsTo
    {
        return $this->belongsTo(MeetingTranscriptSpeaker::class, 'meeting_transcript_speakers_id');
    }
}
