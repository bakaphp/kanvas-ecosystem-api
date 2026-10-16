<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Elead\Actions;

use Baka\Contracts\AppInterface;
use Baka\Support\Str;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Elead\Entities\LeadSource as LeadSourceEntity;
use Kanvas\Connectors\Elead\Enums\CustomFieldEnum;
use Kanvas\Guild\Leads\Models\LeadType;
use Kanvas\Guild\LeadSources\Actions\CreateLeadSourceAction;
use Kanvas\Guild\LeadSources\DataTransferObject\LeadSource;

class SyncLeadSourceAction
{
    public function __construct(
        protected AppInterface $app,
        protected Companies $company,
        protected bool $fresh = false,
    ) {
    }

    /**
     * eLeads only exposes name + upType per source, so the source carries no
     * description and many sources share one upType — the type is find-or-create.
     */
    public function execute(): int
    {
        $synced = 0;

        foreach (LeadSourceEntity::getAll($this->app, $this->company, $this->fresh) as $leadSource) {
            $name = Str::trimToNull($leadSource->name);

            if ($name === null) {
                continue;
            }

            $leadType = $this->findOrCreateLeadType($leadSource->upType);

            $newSource = new CreateLeadSourceAction(
                new LeadSource(
                    app: $this->app,
                    company: $this->company,
                    leads_types_id: $leadType?->getId(),
                    name: $name,
                    is_active: $leadSource->isActive ?? true,
                )
            )->execute();

            $newSource->set(CustomFieldEnum::LEAD_SOURCE_ID->value, $name);
            $synced++;
        }

        return $synced;
    }

    private function findOrCreateLeadType(?string $upType): ?LeadType
    {
        $upType = Str::trimToNull($upType);

        if ($upType === null) {
            return null;
        }

        return LeadType::firstOrCreate(
            [
                'name' => $upType,
                'apps_id' => $this->app->getId(),
                'companies_id' => $this->company->getId(),
            ],
            [
                'description' => $upType,
                'is_active' => 1,
            ]
        );
    }
}
