<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\FollowUp\Actions;

use Kanvas\Intelligence\FollowUp\DataTransferObject\FollowUp as FollowUpData;
use Kanvas\Intelligence\FollowUp\Models\FollowUp;

class UpdateFollowUpAction
{
    public function __construct(
        protected readonly FollowUp $followUp,
        protected readonly FollowUpData $data,
    ) {
    }

    public function execute(): FollowUp
    {
        $this->followUp->pipelines_id = $this->data->pipeline->getId();
        $this->followUp->name = $this->data->name;
        $this->followUp->follow_up_type = $this->data->follow_up_type->value;
        $this->followUp->config = $this->data->config;
        $this->followUp->saveOrFail();

        return $this->followUp;
    }
}
