<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Calendly\Actions;

use Kanvas\Connectors\Calendly\Enums\CustomFieldEnum;
use Kanvas\Guild\Customers\DataTransferObject\Address;
use Kanvas\Guild\Customers\DataTransferObject\Contact;
use Kanvas\Guild\Customers\DataTransferObject\People;
use Kanvas\Guild\Leads\Actions\CreateLeadAction;
use Kanvas\Guild\Leads\DataTransferObject\Lead as LeadData;
use Kanvas\Workflow\Models\ReceiverWebhookCall;
use Spatie\LaravelData\DataCollection;

class ProcessCalendlyInviteeAction
{
    public function __construct(
        protected ReceiverWebhookCall $webhookRequest
    ) {
    }

    public function execute(): array
    {
        $payload = (array) $this->webhookRequest->payload;
        $receiver = $this->webhookRequest->receiverWebhook;
        $app = $receiver->app;
        $company = $receiver->company;
        $user = $receiver->user;
        $branch = $company->defaultBranch;

        /** @var array<string, mixed> $config */
        $config = $receiver->configuration ?? [];

        $eventType = $payload['event'] ?? '';
        $inviteePayload = $payload['payload'] ?? [];

        $invitee = $inviteePayload['invitee'] ?? [];
        $event = $inviteePayload['event'] ?? [];

        $name = $invitee['name'] ?? '';
        $email = $invitee['email'] ?? '';
        $phone = $invitee['text_reminder_number'] ?? '';

        $nameParts = explode(' ', $name, 2);
        $firstname = $nameParts[0] ?? '';
        $lastname = $nameParts[1] ?? '';

        $title = $name !== '' ? $name : ($email !== '' ? $email : 'Calendly Invitee');

        $eventName = $event['name'] ?? '';
        $eventUri = $event['uri'] ?? '';
        $inviteeUri = $invitee['uri'] ?? '';
        if ($eventName !== '') {
            $title = $eventName . ' - ' . $title;
        }

        $contacts = [];
        if ($email !== '') {
            $contacts[] = ['type' => 'email', 'value' => $email];
        }
        if ($phone !== '') {
            $contacts[] = ['type' => 'phone', 'value' => $phone];
        }

        $questionsAndAnswers = $invitee['questions_and_answers'] ?? [];
        foreach ($questionsAndAnswers as $qa) {
            $answer = $qa['answer'] ?? '';
            if (filter_var($answer, FILTER_VALIDATE_EMAIL)) {
                $contacts[] = ['type' => 'email', 'value' => $answer];
            }
        }

        $pipelineStageId = (int) ($config['pipeline_stage_id'] ?? 0);
        $sourceId = (int) ($config['source_id'] ?? 0);

        $leadDto = new LeadData(
            app: $app,
            branch: $branch,
            user: $user,
            title: $title,
            pipeline_stage_id: $pipelineStageId,
            people: People::from([
                'app' => $app,
                'branch' => $branch,
                'user' => $user,
                'firstname' => $firstname,
                'lastname' => $lastname,
                'contacts' => Contact::collect($contacts, DataCollection::class),
                'address' => Address::collect([], DataCollection::class),
                'id' => 0,
            ]),
            source_id: $sourceId,
            custom_fields: [
                CustomFieldEnum::CALENDLY_EVENT_URI->value => $eventUri,
                CustomFieldEnum::CALENDLY_INVITEE_URI->value => $inviteeUri,
                CustomFieldEnum::CALENDLY_EVENT_TYPE->value => $eventType,
                CustomFieldEnum::CALENDLY_EVENT_NAME->value => $eventName,
                'calendly_questions_and_answers' => $questionsAndAnswers,
            ],
        );

        $lead = new CreateLeadAction($leadDto)->execute();

        return [
            'lead_id' => $lead->getId(),
            'event' => $eventType,
            'invitee_email' => $email,
        ];
    }
}
