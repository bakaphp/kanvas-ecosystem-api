<?php

declare(strict_types=1);

namespace Kanvas\Guild\Customers\Observers;

use Kanvas\Guild\Customers\Models\Contact;
use Kanvas\Workflow\Enums\WorkflowEnum;

class ContactObserver
{
    public function creating(Contact $contact): void
    {
        $this->cleanPhoneNumber($contact);
    }

    public function updating(Contact $contact): void
    {
        $this->cleanPhoneNumber($contact);
    }

    public function created(Contact $contact): void
    {
        $this->runWorkflow($contact);
    }

    public function updated(Contact $contact): void
    {
        $this->runWorkflow($contact);
    }

    private function cleanPhoneNumber(Contact $contact): void
    {
        if (! empty($contact->value) && Contact::isPhoneType((int) $contact->contacts_types_id)) {
            $contact->value = Contact::cleanPhone($contact->value);
        }
    }

    private function runWorkflow(Contact $contact): void
    {
        // `people` resolves to null when the person is soft-deleted, which happens on import of
        // historical records that were deleted at the source. A contact with no reachable person
        // has no workflow context, so there is nothing to fire.
        if ($contact->people?->company?->isAIEnabled() !== true) {
            return;
        }

        $contact->fireWorkflow(
            WorkflowEnum::CONTACT_SAVED->value,
            true,
            [
               'company' => $contact->people->company,
               'app' => $contact->people->app,
            ]
        );
    }
}
