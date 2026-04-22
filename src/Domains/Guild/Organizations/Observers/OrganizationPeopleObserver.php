<?php

declare(strict_types=1);

namespace Kanvas\Guild\Organizations\Observers;

use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Guild\Organizations\Models\OrganizationPeople;

class OrganizationPeopleObserver
{
    public function created(OrganizationPeople $pivot): void
    {
        Organization::where('id', $pivot->organizations_id)->increment('total_employees');
    }

    public function deleted(OrganizationPeople $pivot): void
    {
        Organization::where('id', $pivot->organizations_id)
            ->where('total_employees', '>', 0)
            ->decrement('total_employees');
    }
}
