<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\FollowUp\Actions;

use Kanvas\Intelligence\FollowUp\DataTransferObject\FollowUpDay as FollowUpDayData;
use Kanvas\Intelligence\FollowUp\Models\FollowUpDay;

class UpdateFollowUpDayAction
{
    public function __construct(
        protected readonly FollowUpDay $followUpDay,
        protected readonly FollowUpDayData $data,
    ) {
    }

    public function execute(): FollowUpDay
    {
        $this->followUpDay->pipeline_stages_id = $this->data->pipelineStage->getId();
        $this->followUpDay->name = $this->data->name;
        $this->followUpDay->time_value = $this->data->time_value;
        $this->followUpDay->time_unit = $this->data->time_unit;
        $this->followUpDay->weight = $this->data->weight;
        $this->followUpDay->calendar_day = $this->data->calendar_day;
        $this->followUpDay->move_to_stage_id = $this->data->move_to_stage_id;
        $this->followUpDay->send_message = $this->data->send_message;
        $this->followUpDay->saveOrFail();

        return $this->followUpDay;
    }
}
