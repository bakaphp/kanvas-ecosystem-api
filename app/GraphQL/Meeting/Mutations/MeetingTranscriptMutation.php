<?php

declare(strict_types=1);

namespace App\GraphQL\Meeting\Mutations;

use App\GraphQL\Meeting\Concerns\ResolvesOwnedMeetings;
use Kanvas\Meeting\Transcripts\Actions\CreateMeetingTranscriptAction;
use Kanvas\Meeting\Transcripts\Actions\StoreMeetingTranscriptSegmentsAction;
use Kanvas\Meeting\Transcripts\Actions\UpdateMeetingTranscriptSpeakerAction;
use Kanvas\Meeting\Transcripts\Actions\UpdateMeetingTranscriptStatusAction;
use Kanvas\Meeting\Transcripts\DataTransferObject\MeetingTranscript as MeetingTranscriptData;
use Kanvas\Meeting\Transcripts\Enums\MeetingTranscriptStatusEnum;
use Kanvas\Meeting\Transcripts\Models\MeetingTranscript;
use Kanvas\Meeting\Transcripts\Models\MeetingTranscriptSpeaker;

class MeetingTranscriptMutation
{
    use ResolvesOwnedMeetings;

    public function create(mixed $rootValue, array $request): MeetingTranscript
    {
        $input = $request['input'];

        return new CreateMeetingTranscriptAction(
            new MeetingTranscriptData(
                meeting: $this->ownedMeeting($request['meeting_id']),
                provider: (string) $input['provider'],
                model: (string) $input['model'],
                providerJobId: $input['provider_job_id'] ?? null,
            ),
        )->execute();
    }

    public function updateStatus(mixed $rootValue, array $request): MeetingTranscript
    {
        $input = $request['input'];

        return new UpdateMeetingTranscriptStatusAction(
            transcript: $this->ownedTranscript($request['id']),
            status: MeetingTranscriptStatusEnum::from((string) $input['status']),
            errorCode: $input['error_code'] ?? null,
            providerJobId: $input['provider_job_id'] ?? null,
        )->execute();
    }

    public function storeSegments(mixed $rootValue, array $request): MeetingTranscript
    {
        return new StoreMeetingTranscriptSegmentsAction(
            $this->ownedTranscript($request['id']),
            $request['segments'],
        )->execute();
    }

    public function updateSpeaker(mixed $rootValue, array $request): MeetingTranscriptSpeaker
    {
        return new UpdateMeetingTranscriptSpeakerAction(
            $this->ownedSpeaker($request['id']),
            $request['display_name'] ?? null
        )->execute();
    }
}
