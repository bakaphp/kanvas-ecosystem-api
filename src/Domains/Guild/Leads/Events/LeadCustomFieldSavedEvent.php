<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Kanvas\Guild\Leads\Models\Lead;

/**
 * Fired synchronously after every lead custom-field write. Every path (the setCustomField mutation,
 * create/update lead, CRM pulls through SyncLeadByThirdPartyCustomFieldAction, a direct `set()`) ends
 * in `Lead::set()`, so a listener here sees a field however it was saved.
 */
class LeadCustomFieldSavedEvent
{
    use Dispatchable;

    public function __construct(
        public readonly Lead $lead,
        public readonly string $name,
    ) {
    }
}
