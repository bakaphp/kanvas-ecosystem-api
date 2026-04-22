<?php

declare(strict_types=1);

namespace Kanvas\Guild\Organizations\Observers;

use Kanvas\Guild\Organizations\Models\Organization;

class OrganizationObserver
{
    public function updating(Organization $organization): void
    {
        $organization->clearLightHouseCache(withKanvasConfiguration: false);
    }
}
