<?php

declare(strict_types=1);

namespace Kanvas\HumanResources\Meetings\Actions;

use Kanvas\HumanResources\Meetings\DataTransferObject\Meeting as MeetingData;
use Kanvas\HumanResources\Meetings\Models\Meeting;

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
