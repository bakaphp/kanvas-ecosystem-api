<?php

declare(strict_types=1);

namespace App\GraphQL\HumanResources\Queries\Meeting;

use App\GraphQL\HumanResources\Concerns\ResolvesOwnedMeetings;
use Illuminate\Database\Eloquent\Builder;
use Kanvas\HumanResources\Meetings\Transcripts\Models\MeetingTranscriptSegment;

class MeetingTranscriptQuery
{
    use ResolvesOwnedMeetings;

    public function segments(mixed $root, array $request): Builder
    {
        return MeetingTranscriptSegment::query()
            ->where('meeting_transcripts_id', $this->ownedTranscript($request['transcript_id'])->getId())
            ->orderBy('position');
    }
}
