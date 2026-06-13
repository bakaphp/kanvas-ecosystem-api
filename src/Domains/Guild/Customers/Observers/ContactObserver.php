<?php

declare(strict_types=1);

namespace Kanvas\Guild\Customers\Observers;

use Illuminate\Support\Facades\Log;
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
        $this->traceContactWrite($contact, 'created');
        $this->runWorkflow($contact);
    }

    public function updated(Contact $contact): void
    {
        $this->traceContactWrite($contact, 'updated');
        $this->runWorkflow($contact);
    }

    public function deleting(Contact $contact): void
    {
        $this->traceContactWrite($contact, 'deleting');
    }

    /**
     * @todo temporary diagnostic — captures the Kanvas call chain that creates/deletes a
     * contact, to find the path that recreates contacts on the VinSolution pull. Remove after.
     */
    private function traceContactWrite(Contact $contact, string $event): void
    {
        $trace = [];
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 30) as $frame) {
            $fn = ($frame['class'] ?? '') . ($frame['type'] ?? '') . ($frame['function'] ?? '');
            if (str_contains($fn, 'Kanvas') || str_contains($fn, 'App\\')) {
                $trace[] = $fn . ':' . ($frame['line'] ?? '');
            }
        }

        Log::warning('contact_write_trace', [
            'event' => $event,
            'id' => $contact->id,
            'peoples_id' => $contact->peoples_id,
            'value' => $contact->value,
            'type' => $contact->contacts_types_id,
            'trace' => array_slice($trace, 0, 15),
        ]);
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
}
