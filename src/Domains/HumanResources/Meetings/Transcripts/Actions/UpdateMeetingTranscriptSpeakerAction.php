<?php

declare(strict_types=1);

namespace Kanvas\HumanResources\Meetings\Transcripts\Actions;

use Baka\Support\Str;
use Kanvas\HumanResources\Meetings\Transcripts\Models\MeetingTranscriptSpeaker;

class UpdateMeetingTranscriptSpeakerAction
{
    public function __construct(
        protected readonly MeetingTranscriptSpeaker $speaker,
        protected readonly ?string $displayName,
    ) {
    }

    public function execute(): MeetingTranscriptSpeaker
    {
        $this->speaker->display_name = Str::trimToNull($this->displayName);
        $this->speaker->saveOrFail();

        return $this->speaker;
    }
}
