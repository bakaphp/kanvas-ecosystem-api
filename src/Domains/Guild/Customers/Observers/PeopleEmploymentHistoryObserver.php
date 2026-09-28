<?php

declare(strict_types=1);

namespace Kanvas\Guild\Customers\Observers;

use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Customers\Models\PeopleEmploymentHistory as ModelsPeopleEmploymentHistory;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Guild\Organizations\Models\OrganizationPeople;

class PeopleEmploymentHistoryObserver
{
    public function created(ModelsPeopleEmploymentHistory $peopleHistory): void
    {
        // `people` resolves to null when the person is soft-deleted — which happens when
        // importing historical records that were already deleted at the source. The organization
        // link is meaningless without them, and addPeopleToOrganization() has a non-nullable
        // parameter, so this must be guarded rather than passed through.
        if ($peopleHistory->organization instanceof Organization
            && $peopleHistory->people instanceof People
        ) {
            OrganizationPeople::addPeopleToOrganization($peopleHistory->organization, $peopleHistory->people);
        }
    }
}
