<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\Actions;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Leads\DataTransferObject\LeadType as LeadTypeData;
use Kanvas\Guild\Leads\Models\LeadType;

/**
 * Onboarding seeds the default types (Warm, ...) and not every company went through it, so filing a
 * lead under a name must not fail on the type being absent.
 */
final class CreateLeadTypeByNameAction
{
    public function __construct(
        private readonly Apps $app,
        private readonly Companies $company,
        private readonly string $name
    ) {
    }

    public function execute(): LeadType
    {
        $leadType = LeadType::query()
            ->fromApp($this->app)
            ->fromCompany($this->company)
            ->notDeleted()
            ->where('name', $this->name)
            ->first();

        return $leadType ?? new CreateLeadTypeAction(
            new LeadTypeData(
                apps: $this->app,
                companies: $this->company,
                name: $this->name,
                description: $this->name,
                is_active: 1
            )
        )->execute();
    }
}
