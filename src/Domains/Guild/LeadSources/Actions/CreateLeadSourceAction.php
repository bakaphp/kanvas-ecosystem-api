<?php

declare(strict_types=1);

namespace Kanvas\Guild\LeadSources\Actions;

use Kanvas\Guild\LeadSources\DataTransferObject\LeadSource;
use Kanvas\Guild\LeadSources\Models\LeadSource as LeadSourceModel;

class CreateLeadSourceAction
{
    protected LeadSource $leadSource;

    public function __construct(LeadSource $leadSource)
    {
        $this->leadSource = $leadSource;
    }

    public function execute(): LeadSourceModel
    {
        return LeadSourceModel::create($this->leadSource->toArray());
    }
}
