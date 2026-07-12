<?php

declare(strict_types=1);

namespace Kanvas\Guild\Organizations\Observers;

use Kanvas\Guild\Customers\Models\Contact;
use Kanvas\Guild\Organizations\Models\Organization;

class OrganizationObserver
{
    public function creating(Organization $organization): void
    {
        $this->cleanPhoneNumber($organization);
    }

    public function updating(Organization $organization): void
    {
        $this->cleanPhoneNumber($organization);
        $organization->clearLightHouseCache(withKanvasConfiguration: false);
    }

    private function cleanPhoneNumber(Organization $organization): void
    {
        if (! empty($organization->phone)) {
            $organization->phone = Contact::cleanPhone($organization->phone);
        }
    }
}
