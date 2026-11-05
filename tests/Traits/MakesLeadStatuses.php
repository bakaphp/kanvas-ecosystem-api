<?php

declare(strict_types=1);

namespace Tests\Traits;

use Kanvas\Guild\Leads\Models\LeadStatus;

/**
 * CI seeds only Active (1) and Inactive (2), both `is_open`, so a test that needs a closed status
 * creates the global one it expects; a status row is closed unless it sets `is_open`.
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
