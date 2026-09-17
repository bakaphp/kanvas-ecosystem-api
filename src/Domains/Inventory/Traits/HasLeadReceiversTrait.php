<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Traits;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Kanvas\Guild\Leads\Models\LeadReceiver;

trait HasLeadReceiversTrait
{
    public function leadReceivers(): HasMany
    {
        // Composite key so an eager load keeps every row on its own tenant.
        return $this->hasMany(
            LeadReceiver::class,
            ['companies_id', 'apps_id'],
            ['companies_id', 'apps_id']
        )->notDeleted();
    }
}
