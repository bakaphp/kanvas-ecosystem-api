<?php

declare(strict_types=1);

namespace Kanvas\Guild\Customers\Observers;

use Kanvas\Guild\Customers\Enums\ContactTypeEnum;
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
        $phoneTypes = [
            ContactTypeEnum::PHONE->value,
            ContactTypeEnum::CELLPHONE->value,
            ContactTypeEnum::WORK_PHONE->value,
        ];

        if (! empty($contact->value) && in_array($contact->contacts_types_id, $phoneTypes, true)) {
            $contact->value = preg_replace('/\D/', '', $contact->value);
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
}
