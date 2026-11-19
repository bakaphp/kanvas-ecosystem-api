<?php

declare(strict_types=1);

namespace App\GraphQL\Meeting\Mutations;

use App\GraphQL\Meeting\Concerns\ResolvesOwnedMeetings;
use Kanvas\Meeting\Notes\Actions\SaveMeetingNoteAction;
use Kanvas\Meeting\Notes\DataTransferObject\MeetingNote as MeetingNoteData;
use Kanvas\Meeting\Notes\Enums\MeetingNoteKindEnum;
use Kanvas\Meeting\Notes\Models\MeetingNote;

class MeetingNoteMutation
{
    use ResolvesOwnedMeetings;

    public function save(mixed $rootValue, array $request): MeetingNote
    {
        $input = $request['input'];

        return new SaveMeetingNoteAction(
            new MeetingNoteData(
                meeting: $this->ownedMeeting($request['meeting_id']),
                body: (string) $input['body'],
                kind: MeetingNoteKindEnum::from((string) ($input['kind'] ?? MeetingNoteKindEnum::MANUAL->value)),
                transcript: isset($input['transcript_id']) ? $this->ownedTranscript($input['transcript_id']) : null,
            ),
        )->execute();
    }
}
