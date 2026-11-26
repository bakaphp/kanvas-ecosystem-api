<?php

declare(strict_types=1);

namespace Kanvas\HumanResources\Meetings\Notes\DataTransferObject;

use Kanvas\HumanResources\Meetings\Models\Meeting;
use Kanvas\HumanResources\Meetings\Notes\Enums\MeetingNoteKindEnum;
use Kanvas\HumanResources\Meetings\Transcripts\Models\MeetingTranscript;
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
