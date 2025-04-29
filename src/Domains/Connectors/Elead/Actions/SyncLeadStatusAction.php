<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Elead\Actions;

use Baka\Contracts\AppInterface;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Elead\Entities\LeadStatus;
use Kanvas\Connectors\Elead\Enums\CustomFieldEnum;
use Kanvas\Guild\Leads\Models\LeadStatus as ModelsLeadStatus;

class SyncLeadStatusAction
{
    public function __construct(
        protected AppInterface $app,
        protected Companies $company,
    ) {
    }

    public function execute(): void
    {
        foreach (LeadStatus::getAll($this->app, $this->company) as $leadStatus) {
            $newSource = ModelsLeadStatus::firstOrCreate([
                'name' => $leadStatus->status,
            ]);

            //set custom field for relationship

            $statusSubList = [];
            foreach (array_values($leadStatus->subStatus) as $subStatus) {
                $value = current($subStatus);

                $statusSubList[$value] = $value;
            }

            $newSource->set(CustomFieldEnum::LEAD_SUB_STATUS->value, $statusSubList);
        }
    }
}
