<?php

declare(strict_types=1);

namespace Kanvas\Guild\Customers\Observers;

use Kanvas\Guild\Customers\Models\PeopleEmploymentHistory as ModelsPeopleEmploymentHistory;
use Kanvas\Guild\Organizations\Models\OrganizationPeople;

class PeopleEmploymentHistoryObserver
{
    public function created(ModelsPeopleEmploymentHistory $peopleHistory): void
    {
        OrganizationPeople::addPeopleToOrganization($peopleHistory->organization, $peopleHistory->people);
    }
}
