<?php

declare(strict_types=1);

namespace App\GraphQL\HumanResources\Concerns;

use App\GraphQL\Concerns\ResolvesActingContext;
use Kanvas\HumanResources\Meetings\Models\Meeting;
use Kanvas\HumanResources\Meetings\Transcripts\Models\MeetingTranscript;
use Kanvas\HumanResources\Meetings\Transcripts\Models\MeetingTranscriptSpeaker;

trait ResolvesOwnedMeetings
{
    use ResolvesActingContext;

    protected function ownedMeeting(mixed $id): Meeting
    {
        $ctx = $this->actingContext();

        return Meeting::getByIdFromOwner(
            (int) $id,
            $ctx->user,
            $ctx->company,
            $ctx->app
        );
    }

    protected function ownedTranscript(mixed $id): MeetingTranscript
    {
        $ctx = $this->actingContext();

        return MeetingTranscript::getByIdFromOwner(
            (int) $id,
            $ctx->user,
            $ctx->company,
            $ctx->app
        );
    }

    protected function ownedSpeaker(mixed $id): MeetingTranscriptSpeaker
    {
        $ctx = $this->actingContext();

        return MeetingTranscriptSpeaker::getByIdFromOwner(
            (int) $id,
            $ctx->user,
            $ctx->company,
            $ctx->app
        );
    }
}
