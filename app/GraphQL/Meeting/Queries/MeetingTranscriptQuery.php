<?php

declare(strict_types=1);

namespace App\GraphQL\Meeting\Queries;

use App\GraphQL\Meeting\Concerns\ResolvesOwnedMeetings;
use Illuminate\Database\Eloquent\Builder;
use Kanvas\Meeting\Transcripts\Models\MeetingTranscriptSegment;

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
