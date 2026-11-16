<?php

declare(strict_types=1);

namespace Kanvas\Meeting\Meetings\Actions;

use Kanvas\Meeting\Meetings\DataTransferObject\Meeting as MeetingData;
use Kanvas\Meeting\Meetings\Models\Meeting;

class UpdateMeetingAction
{
    public function __construct(
        protected readonly Meeting $meeting,
        protected readonly MeetingData $data,
    ) {
    }

    public function execute(): Meeting
    {
        $this->meeting->title = $this->data->title;
        $this->meeting->started_at = $this->data->startedAt;
        $this->meeting->ended_at = $this->data->endedAt;
        $this->meeting->people_id = $this->data->people?->getId();
        $this->meeting->saveOrFail();

        return $this->meeting;
    }
}
