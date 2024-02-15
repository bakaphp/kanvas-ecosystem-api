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
        $leadType->apps_id = $this->leadTypeDto->apps->getId();
        $leadType->companies_id = $this->leadTypeDto->companies->getId();
        $leadType->name = $this->leadTypeDto->name;
        $leadType->description = $this->leadTypeDto->description;
        $leadType->is_active = $this->leadTypeDto->is_active;
        $leadType->saveOrFail();

        return $leadType;
    }
}
