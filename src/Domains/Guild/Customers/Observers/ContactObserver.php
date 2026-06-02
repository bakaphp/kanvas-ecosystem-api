<?php

declare(strict_types=1);

namespace Kanvas\Guild\Customers\Observers;

use Kanvas\Guild\Customers\Enums\ContactTypeEnum;
use Kanvas\Guild\Customers\Models\Contact;
use Kanvas\Social\Messages\Actions\CreateMessageAction;
use Kanvas\Social\Messages\DataTransferObject\MessageInput;
use Kanvas\Social\MessagesTypes\Actions\CreateMessageTypeAction;
use Kanvas\Social\MessagesTypes\DataTransferObject\MessageTypeInput;
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

    private function logOptOutChange(Contact $contact): void
    {
        if (! $contact->wasChanged('is_opt_out')) {
            return;
        }

        $people = $contact->people;
        if (! $people) {
            return;
        }

        $lead = $people->leads()->notDeleted()->first();
        if (! $lead) {
            return;
        }

        $notesChannel = $lead->systemNotes;
        if (! $notesChannel) {
            return;
        }

        $content = $contact->is_opt_out === 1
            ? 'Do Not Text enabled'
            : 'Do Not Text disabled';

        $messageType = new CreateMessageTypeAction(
            new MessageTypeInput(
                apps_id: $lead->app->getId(),
                languages_id: 1,
                name: 'ai-control',
                verb: 'ai-control',
                template: '{{message}}',
                templates_plura: '{{message}}',
            )
        )->execute();

        $createMessageAction = new CreateMessageAction(
            new MessageInput(
                app: $lead->app,
                company: $lead->company,
                user: $lead->user,
                type: $messageType,
                message: [
                    'content' => $content,
                    'from_me' => true,
                ],
            )
        );
        $createMessageAction->runWorkflow = true;
        $message = $createMessageAction->execute();
        $notesChannel->addMessage($message, $lead->user);
    }
}
