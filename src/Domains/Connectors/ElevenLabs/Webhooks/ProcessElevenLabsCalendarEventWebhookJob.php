<?php

declare(strict_types=1);

namespace Kanvas\Connectors\ElevenLabs\Webhooks;

use Baka\Support\Str;
use Kanvas\Event\Events\Actions\CreateEventAction;
use Kanvas\Event\Events\DataTransferObject\Event as EventData;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Customers\Repositories\PeoplesRepository;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Repositories\LeadsRepository;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Kanvas\Workflow\Jobs\ProcessWebhookJob;
use Override;

class ProcessElevenLabsCalendarEventWebhookJob extends ProcessWebhookJob
{
    #[Override]
    public function execute(): array
    {
        $payload = (array) $this->webhookRequest->payload;
        $phone = isset($payload['phone']) ? (string) $payload['phone'] : null;

        if ($phone === null || $phone === '') {
            $this->failedReturnHttpCode = 422;

            return ['status' => 422, 'message' => 'Phone number is required'];
        }

        $date = isset($payload['date']) ? (string) $payload['date'] : null;
        if ($date === null || $date === '') {
            $this->failedReturnHttpCode = 422;

            return ['status' => 422, 'message' => 'Date is required'];
        }

        $app = $this->receiver->app;
        $company = $this->receiver->company;
        $user = $this->receiver->user;
        $normalizedPhone = Str::normalizePhoneNumber($phone);

        $lead = $this->findLeadByPhone($normalizedPhone, $phone);

        if (! $lead) {
            $this->failedReturnHttpCode = 404;

            return ['status' => 404, 'message' => 'No lead found for phone: ' . $normalizedPhone];
        }

        $this->updateLeadPeopleInfo($lead, $payload);

        $startTime = isset($payload['start_time']) ? (string) $payload['start_time'] : null;
        $endTime = isset($payload['end_time']) ? (string) $payload['end_time'] : null;

        $eventName = isset($payload['event_name']) && $payload['event_name'] !== ''
            ? (string) $payload['event_name']
            : trim((string) $lead->people->firstname . ' ' . (string) $lead->people->lastname) . ' Appointment';

        $eventDto = EventData::fromMultiple($app, $user, $company, [
            'name' => $eventName,
            'description' => isset($payload['description']) ? (string) $payload['description'] : null,
            'meeting_link' => isset($payload['meeting_link']) ? (string) $payload['meeting_link'] : null,
            'dates' => [
                [
                    'date' => $date,
                    'start_time' => $startTime,
                    'end_time' => $endTime,
                ],
            ],
            'resources' => [
                [
                    'resources_id' => $lead->getId(),
                    'resources_type' => 'lead',
                ],
            ],
        ]);

        $event = new CreateEventAction($eventDto)->execute();

        $customerName = trim((string) $lead->people->firstname . ' ' . (string) $lead->people->lastname);
        $conversationSummary = isset($payload['conversation_summary']) && (string) $payload['conversation_summary'] !== ''
            ? (string) $payload['conversation_summary']
            : sprintf(
                'Appointment scheduled via ElevenLabs voice agent for %s on %s%s.',
                $customerName !== '' ? $customerName : 'customer',
                $date,
                $startTime !== null ? ' at ' . $startTime : '',
            );

        $lead->fireWorkflow(
            WorkflowEnum::HANDOFF->value,
            true,
            [
                'app' => $app,
                'handoff_type' => 'human',
                'source' => 'elevenlabs_appointment',
                'conversation_summary' => $conversationSummary,
            ]
        );

        return [
            'message' => 'Calendar event created and handoff triggered',
            'event_id' => $event->getId(),
            'event_name' => $event->name,
            'lead_id' => $lead->getId(),
            'lead_uuid' => $lead->uuid,
            'date' => $date,
            'start_time' => $startTime,
            'end_time' => $endTime,
        ];
    }

    protected function updateLeadPeopleInfo(Lead $lead, array $payload): void
    {
        /** @var \Kanvas\Guild\Customers\Models\People $people */
        $people = $lead->people;
        $updated = false;

        $firstname = isset($payload['firstname']) ? (string) $payload['firstname'] : null;
        $lastname = isset($payload['lastname']) ? (string) $payload['lastname'] : null;

        if ($firstname !== null && $firstname !== '') {
            $people->firstname = $firstname;
            $updated = true;
        }

        if ($lastname !== null && $lastname !== '') {
            $people->lastname = $lastname;
            $updated = true;
        }

        if ($updated) {
            $people->saveOrFail();
        }
    }

    protected function findLeadByPhone(string $normalizedPhone, string $rawPhone): ?Lead
    {
        $phoneWithCountryCode = str_replace('+', '', $rawPhone);

        $query = PeoplesRepository::getByPhoneNumber(
            app: $this->receiver->app,
            company: $this->receiver->company,
            phoneNumbers: [$normalizedPhone, $phoneWithCountryCode],
        );

        $allCustomers = $query->get();

        $people = $allCustomers->first(function (People $customer): bool {
            return LeadsRepository::getPeopleActiveLead($customer) !== null;
        }) ?? $allCustomers->first();

        if (! $people) {
            return null;
        }

        return LeadsRepository::getPeopleActiveLead($people);
    }
}
