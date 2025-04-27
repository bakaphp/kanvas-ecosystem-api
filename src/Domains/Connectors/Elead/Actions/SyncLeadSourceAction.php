<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Elead\Actions;

use Baka\Contracts\AppInterface;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Elead\Entities\LeadSource as LeadSourceEntity;
use Kanvas\Connectors\Elead\Enums\CustomFieldEnum;
use Kanvas\Guild\Leads\Actions\CreateLeadTypeAction;
use Kanvas\Guild\Leads\DataTransferObject\LeadType;
use Kanvas\Guild\LeadSources\Actions\CreateLeadSourceAction;
use Kanvas\Guild\LeadSources\DataTransferObject\LeadSource;

class SyncLeadSourceAction
{
    public function __construct(
        protected AppInterface $app,
        protected Companies $company
    ) {
    }

    public function execute(): void
    {
        foreach (LeadSourceEntity::getAll($this->app, $this->company) as $leadSource) {
            $newSource = new CreateLeadSourceAction(
                new LeadSource(
                    $this->app,
                    $this->company,
                    '',
                    $leadSource->name,
                    $leadSource->isActive,
                    $leadSource->description,
                )
            )->execute();

            new CreateLeadTypeAction(
                new LeadType(
                    $this->app,
                    $this->company,
                    $leadSource->upType,
                    $leadSource->description,
                    1
                )
            )->execute();

            $newSource->is_deleted = 0; // (int) $leadSource->isActive;
            $newSource->update();

            //set custom field for relationship
            $newSource->set(CustomFieldEnum::LEAD_SOURCE_ID->value, $leadSource->name);
        }
    }
}
