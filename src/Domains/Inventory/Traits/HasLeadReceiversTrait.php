<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Traits;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Kanvas\Guild\Leads\Models\LeadReceiver;

/**
 * Shared by Products and Variants — a storefront form is a LeadReceiver of the row's own company.
 * The key is the (companies_id, apps_id) pair via Compoships so an eager load over a batch
 * constrains every row on its own tenant instead of the first row's, which a single-column
 * hasMany plus a `where apps_id` would do. Lives on `crm`, so it is always a second query.
 */
trait HasLeadReceiversTrait
{
    public function leadReceivers(): HasMany
    {
        return $this->hasMany(
            LeadReceiver::class,
            ['companies_id', 'apps_id'],
            ['companies_id', 'apps_id']
        )->notDeleted();
    }
}
