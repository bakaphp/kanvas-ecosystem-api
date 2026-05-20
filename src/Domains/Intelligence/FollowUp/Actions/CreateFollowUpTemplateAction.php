<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\FollowUp\Actions;

use Kanvas\Intelligence\FollowUp\DataTransferObject\FollowUpTemplate as FollowUpTemplateData;
use Kanvas\Intelligence\FollowUp\Models\FollowUpTemplate;

class CreateFollowUpTemplateAction
{
    public function __construct(
        protected readonly FollowUpTemplateData $data,
    ) {
    }

    public function execute(): FollowUpTemplate
    {
        $template = new FollowUpTemplate();
        $template->follow_up_days_id = $this->data->followUpDay->getId();
        $template->communication_channel = $this->data->communication_channel;
        $template->name = $this->data->name;
        $template->template = $this->data->template;
        $template->saveOrFail();

        return $template;
    }
}
