<?php

declare(strict_types=1);

namespace Kanvas\Guild\Organizations\Observers;

use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Actions\CreateEntityNotesChannelAction;
use Kanvas\Guild\Customers\Models\Contact;
use Kanvas\Guild\Duplicates\Jobs\CheckOrganizationDuplicateJob;
use Kanvas\Guild\Organizations\Models\Organization;
use Throwable;

class OrganizationObserver
{
    public function creating(Organization $organization): void
    {
        $this->cleanPhoneNumber($organization);
    }

    public function created(Organization $organization): void
    {
        try {
            CheckOrganizationDuplicateJob::dispatch(Apps::getById((int) $organization->apps_id), $organization->getId());
        } catch (Throwable $e) {
            report($e);
        }

        try {
            new CreateEntityNotesChannelAction($organization)->execute();
        } catch (Throwable $e) {
            report($e);
        }
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
