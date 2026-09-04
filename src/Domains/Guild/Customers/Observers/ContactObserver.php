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
        $this->logOptOutChange($contact);
    }

    private function cleanPhoneNumber(Contact $contact): void
    {
        if (! empty($contact->value) && Contact::isPhoneType((int) $contact->contacts_types_id)) {
            $contact->value = Contact::cleanPhone($contact->value);
        }
    }

    private function runWorkflow(Contact $contact): void
    {
        if (! $contact->people->company->isAIEnabled()) {
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

    private function logOptOutChange(Contact $contact): void
    {
        if (! $contact->wasChanged('is_opt_out')) {
            return;
        }
    }
}
