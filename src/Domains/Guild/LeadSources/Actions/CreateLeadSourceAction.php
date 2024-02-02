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
        $leadSource = new LeadSourceModel();
        $leadSource->apps_id = $this->leadSource->app->getId();
        $leadSource->companies_id = $this->leadSource->company->getId();
        $leadSource->leads_types_id = $this->leadSource->leads_types_id;
        $leadSource->name = $this->leadSource->name;
        $leadSource->description = $this->leadSource->description;
        $leadSource->is_active = $this->leadSource->is_active;
        $leadSource->saveOrFail();

        return $leadSource;
    }
}
