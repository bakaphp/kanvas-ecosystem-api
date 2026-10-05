<?php

declare(strict_types=1);

namespace Tests\Traits;

use Kanvas\Guild\Leads\Models\LeadStatus;

/**
 * CI seeds no `leads_status` rows, so a test that needs a closed status creates the global one it
 * expects; `Lead::OPEN_LEADS_STATUS_IDS` names ids 1 and 2 as open, anything else counts as closed.
 */
trait MakesLeadStatuses
{
    protected static function lostLeadStatusId(): int
    {
        return LeadStatus::query()
            ->where('name', 'Lost')
            ->where('apps_id', 0)
            ->firstOrCreate(['name' => 'Lost', 'apps_id' => 0, 'companies_id' => 0], ['is_default' => 0])
            ->getId();
    }
}
