<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\Actions;

use Kanvas\Guild\Leads\DataTransferObject\LeadType as LeadTypeDto;
use Kanvas\Guild\Leads\Models\LeadType;

class CreateLeadTypeAction
{
    public function __construct(
        private LeadTypeDto $leadTypeDto
    ) {
    }

    public function create(): LeadType
    {
        $leadType = new LeadType();
        $leadType->apps_id = $this->leadTypeDto->app->getId();
        $leadType->companies_id = $this->leadTypeDto->company->getId();
        $leadType->name = $this->leadTypeDto->name;
        $leadType->description = $this->leadTypeDto->description;
        $leadType->is_active = $this->leadTypeDto->is_active;
        $leadType->is_default = $this->leadTypeDto->is_default;
        $leadType->saveOrFail();

        return $leadType;
    }
}
