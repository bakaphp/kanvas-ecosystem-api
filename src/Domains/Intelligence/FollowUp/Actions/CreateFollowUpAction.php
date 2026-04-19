<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\FollowUp\Actions;

use Kanvas\Intelligence\FollowUp\DataTransferObject\FollowUp as FollowUpData;
use Kanvas\Intelligence\FollowUp\Models\FollowUp;

class CreateFollowUpAction
{
    public function __construct(
        protected readonly FollowUpData $data,
    ) {
    }

    public function execute(): FollowUp
    {
        $followUp = new FollowUp();
        $followUp->apps_id = $this->data->app->getId();
        $followUp->companies_id = $this->data->company->getId();
        $followUp->pipelines_id = $this->data->pipeline->getId();
        $followUp->name = $this->data->name;
        $followUp->follow_up_type = $this->data->follow_up_type->value;
        $followUp->config = $this->data->config;
        $followUp->saveOrFail();

        return $followUp;
    }
}
