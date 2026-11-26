<?php

declare(strict_types=1);

namespace App\GraphQL\HumanResources\Mutations\Meeting;

use App\GraphQL\HumanResources\Concerns\ResolvesOwnedMeetings;
use Kanvas\HumanResources\Meetings\Actions\CreateMeetingAction;
use Kanvas\HumanResources\Meetings\Actions\UpdateMeetingAction;
use Kanvas\HumanResources\Meetings\DataTransferObject\Meeting as MeetingData;
use Kanvas\HumanResources\Meetings\Models\Meeting;
use Kanvas\HumanResources\Meetings\Recordings\Actions\UploadMeetingRecordingAction;
use Kanvas\HumanResources\Meetings\Recordings\Models\MeetingRecording;

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
