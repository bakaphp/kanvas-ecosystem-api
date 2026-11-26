<?php

declare(strict_types=1);

namespace Kanvas\HumanResources\Meetings\Notes\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\HumanResources\Meetings\Models\BaseModel;
use Kanvas\HumanResources\Meetings\Models\Meeting;
use Kanvas\HumanResources\Meetings\Notes\Enums\MeetingNoteKindEnum;
use Kanvas\HumanResources\Meetings\Transcripts\Models\MeetingTranscript;

/**
 * @property int $id
 * @property int $meetings_id
 * @property int|null $meeting_transcripts_id
 * @property MeetingNoteKindEnum $kind
 * @property string $body
 */
class MeetingNote extends BaseModel
{
    protected $table = 'meeting_notes';

    protected function casts(): array
    {
        return [
            'kind' => MeetingNoteKindEnum::class,
        ];
    }

    public function meeting(): BelongsTo
    {
        return $this->belongsTo(Meeting::class, 'meetings_id');
    }

    public function transcript(): BelongsTo
    {
        return $this->belongsTo(MeetingTranscript::class, 'meeting_transcripts_id');
    }
}
