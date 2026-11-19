<?php

declare(strict_types=1);

namespace App\GraphQL\Meeting\Mutations;

use App\GraphQL\Meeting\Concerns\ResolvesOwnedMeetings;
use Kanvas\Meeting\Meetings\Actions\CreateMeetingAction;
use Kanvas\Meeting\Meetings\Actions\UpdateMeetingAction;
use Kanvas\Meeting\Meetings\DataTransferObject\Meeting as MeetingData;
use Kanvas\Meeting\Meetings\Models\Meeting;
use Kanvas\Meeting\Recordings\Actions\UploadMeetingRecordingAction;
use Kanvas\Meeting\Recordings\Models\MeetingRecording;

class MeetingMutation
{
    use ResolvesOwnedMeetings;

    public function create(mixed $rootValue, array $request): Meeting
    {
        $ctx = $this->actingContext();

        return new CreateMeetingAction(
            MeetingData::from($ctx->app, $ctx->user, $ctx->company, $request['input']),
        )->execute();
    }

    public function update(mixed $rootValue, array $request): Meeting
    {
        $meeting = $this->ownedMeeting($request['id']);

        return new UpdateMeetingAction(
            $meeting,
            MeetingData::forUpdate($meeting, $request['input']),
        )->execute();
    }

    public function uploadRecording(mixed $rootValue, array $request): MeetingRecording
    {
        return new UploadMeetingRecordingAction(
            $this->ownedMeeting($request['meeting_id']),
            $request['file'],
            $request['input'],
        )->execute();
    }
}
