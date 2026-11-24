<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\Observers;

use Kanvas\Guild\Leads\Models\LeadStatus;

class LeadStatusObserver
{
    /**
     * Connectors create statuses by name and never pass `is_open`, so a row created without it takes
     * the flag from its name or from being the company default. An explicit value always wins.
     */
    public function creating(LeadStatus $status): void
    {
        if ($status->isDirty('is_open')) {
            return;
        }

        $status->is_open = LeadStatus::looksOpen((string) $status->name, (bool) $status->is_default);
    }
}
