<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\FollowUp\Actions;

use Kanvas\Intelligence\FollowUp\DataTransferObject\FollowUpDay as FollowUpDayData;
use Kanvas\Intelligence\FollowUp\Models\FollowUpDay;

class CreateFollowUpDayAction
{
    public function __construct(
        protected readonly FollowUpDayData $data,
    ) {
    }

    public function execute(): FollowUpDay
    {
        $day = new FollowUpDay();
        $day->follow_ups_id = $this->data->followUp->getId();
        $day->pipeline_stages_id = $this->data->pipelineStage->getId();
        $day->name = $this->data->name;
        $day->time_value = $this->data->time_value;
        $day->time_unit = $this->data->time_unit;
        $day->weight = $this->data->weight;
        $day->calendar_day = $this->data->calendar_day;
        $day->move_to_stage_id = $this->data->move_to_stage_id;
        $day->send_message = $this->data->send_message;
        $day->saveOrFail();

        return $day;
    }
}
