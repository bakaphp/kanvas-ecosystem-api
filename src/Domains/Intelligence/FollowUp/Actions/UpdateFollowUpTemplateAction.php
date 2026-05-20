<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\FollowUp\Actions;

use Kanvas\Intelligence\FollowUp\DataTransferObject\FollowUpTemplate as FollowUpTemplateData;
use Kanvas\Intelligence\FollowUp\Models\FollowUpTemplate;

class UpdateFollowUpTemplateAction
{
    public function __construct(
        protected readonly FollowUpTemplate $followUpTemplate,
        protected readonly FollowUpTemplateData $data,
    ) {
    }

    public function execute(): FollowUpTemplate
    {
        $this->followUpTemplate->communication_channel = $this->data->communication_channel;
        $this->followUpTemplate->name = $this->data->name;
        $this->followUpTemplate->template = $this->data->template;
        $this->followUpTemplate->saveOrFail();

        return $this->followUpTemplate;
    }
}
