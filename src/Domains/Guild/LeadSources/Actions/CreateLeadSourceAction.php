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
        $attributes = [
            'companies_id' => $this->leadSource->company->getId(),
            'name' => $this->leadSource->name,
        ];

        // If description is not null, include it in the search criteria
        if ($this->leadSource->description !== null) {
            $attributes['description'] = $this->leadSource->description;
        }

        return LeadSourceModel::updateOrCreate(
            $attributes,
            [
                'companies_id' => $this->leadSource->company->getId(),
                'apps_id' => $this->leadSource->app->getId(),
                'name' => $this->leadSource->name,
                'description' => $this->leadSource->description,
                'is_active' => $this->leadSource->is_active,
                'leads_types_id' => $this->leadSource->leads_types_id,
            ]
        );
    }
}
