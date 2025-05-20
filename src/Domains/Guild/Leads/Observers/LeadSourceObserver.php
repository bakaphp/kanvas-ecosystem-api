<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\Observers;

use Exception;
use Kanvas\Guild\Leads\Models\LeadSource;

class LeadSourceObserver
{
    public function deleting(LeadSource $leadSource): void
    {
        if ($leadSource->leadReceivers->count()) {
            throw new Exception('The Lead Source is in use');
        }
    }
}
