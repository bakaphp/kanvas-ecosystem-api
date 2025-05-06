<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\Observers;

use Exception;
use Kanvas\Guild\Leads\Models\LeadType;

class LeadTypeObserver
{
    public function deleting(LeadType $leadType): void
    {
        if ($leadType->leads->count()) {
            throw new Exception("You can't delete this lead type , because are in use");
        }
    }
}
