<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Calendly\Actions;

use Baka\Contracts\AppInterface;
use Baka\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Calendly\Enums\CustomFieldEnum;
use Kanvas\Guild\Customers\DataTransferObject\Address;
use Kanvas\Guild\Customers\DataTransferObject\Contact;
use Kanvas\Guild\Customers\DataTransferObject\People;
use Kanvas\Guild\Leads\Actions\CreateLeadAction;
use Kanvas\Guild\Leads\Actions\CreateLeadTypeAction;
use Kanvas\Guild\Leads\DataTransferObject\Lead as LeadData;
use Kanvas\Guild\Leads\DataTransferObject\LeadType as LeadTypeData;
use Kanvas\Guild\Leads\Models\LeadType;
use Kanvas\Guild\LeadSources\Actions\CreateLeadSourceAction;
use Kanvas\Guild\LeadSources\DataTransferObject\LeadSource as LeadSourceData;
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

        $eventType = (string) ($payload['event'] ?? '');

        /** @var array<string, mixed> $inviteePayload */
        $inviteePayload = $payload['payload'] ?? [];

        /** @var array<string, mixed> $scheduledEvent */
        $scheduledEvent = $inviteePayload['scheduled_event'] ?? [];

        $name = (string) ($inviteePayload['name'] ?? '');
        $email = (string) ($inviteePayload['email'] ?? '');
        $phone = (string) ($inviteePayload['text_reminder_number'] ?? '');

        ['firstname' => $firstname, 'lastname' => $lastname] = Str::parseFullName(
            fullName: $name,
            firstname: (string) ($inviteePayload['first_name'] ?? ''),
            lastname: (string) ($inviteePayload['last_name'] ?? ''),
        );

        $title = $name !== '' ? $name : ($email !== '' ? $email : 'Calendly Invitee');

        $eventName = (string) ($scheduledEvent['name'] ?? '');
        $eventUri = (string) ($scheduledEvent['uri'] ?? '');
        $inviteeUri = (string) ($inviteePayload['uri'] ?? '');
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

        $questionsAndAnswers = $inviteePayload['questions_and_answers'] ?? [];
        foreach ($questionsAndAnswers as $qa) {
            $answer = $qa['answer'] ?? '';
            if (filter_var($answer, FILTER_VALIDATE_EMAIL)) {
                $contacts[] = ['type' => 'email', 'value' => $answer];
            }
        }

        $pipelineStageId = (int) ($config['pipeline_stage_id'] ?? 0);
        $sourceId = $this->resolveSourceId($app, $company, $config);
        $typeId = $this->resolveTypeId($app, $company, $config);

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
            type_id: $typeId,
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

    protected function resolveSourceId(AppInterface $app, Companies $company, array $config): int
    {
        if (! empty($config['source_id'])) {
            return (int) $config['source_id'];
        }

        $typeId = $this->resolveTypeId($app, $company, $config);

        /** @var Apps $app */
        $source = new CreateLeadSourceAction(
            new LeadSourceData(
                app: $app,
                company: $company,
                leads_types_id: $typeId,
                name: 'Calendly',
                is_active: true,
                description: 'Leads coming from Calendly',
            ),
        )->execute();

        return (int) $source->getId();
    }

    protected function resolveTypeId(AppInterface $app, Companies $company, array $config): int
    {
        if (! empty($config['type_id'])) {
            return (int) $config['type_id'];
        }

        $type = LeadType::where('apps_id', $app->getId())
            ->where('companies_id', $company->getId())
            ->where('name', 'Internet')
            ->where('is_deleted', 0)
            ->first();

        if ($type) {
            return (int) $type->getId();
        }

        /** @var Apps $app */
        $type = new CreateLeadTypeAction(
            new LeadTypeData(
                apps: $app,
                companies: $company,
                name: 'Internet',
                description: 'Internet leads',
                is_active: 1,
            ),
        )->execute();

        return (int) $type->getId();
    }
}
