<?php

declare(strict_types=1);

namespace Kanvas\Meeting\Notes\DataTransferObject;

use Kanvas\Meeting\Meetings\Models\Meeting;
use Kanvas\Meeting\Notes\Enums\MeetingNoteKindEnum;
use Kanvas\Meeting\Transcripts\Models\MeetingTranscript;
use Spatie\LaravelData\Data;

class MeetingNote extends Data
{
    public function __construct(
        public readonly Meeting $meeting,
        public readonly string $body,
        public readonly MeetingNoteKindEnum $kind = MeetingNoteKindEnum::MANUAL,
        public readonly ?MeetingTranscript $transcript = null,
    ) {
    }
}
