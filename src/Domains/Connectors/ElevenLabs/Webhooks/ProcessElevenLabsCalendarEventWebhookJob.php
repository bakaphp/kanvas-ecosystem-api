<?php

declare(strict_types=1);

namespace Kanvas\Connectors\ElevenLabs\Webhooks;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Support\DateHelper;
use Baka\Users\Contracts\UserInterface;
use Kanvas\Event\Events\Actions\CreateEventAction;
use Kanvas\Event\Events\DataTransferObject\Event as EventData;
use Kanvas\Event\Events\Models\EventCategory;
use Kanvas\Event\Support\Setup;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Override;

#[WorkflowAction(
    name: 'ElevenLabs Book Calendar Event',
    description: 'One of the endpoints an ElevenLabs VOICE agent calls back into Kanvas mid-call. These are '
        . 'wired as that agent\'s server-side tools, not chosen as workflow steps — the caller on the '
        . 'phone triggers them. This one BOOKS an appointment for the caller. It writes a real event, '
        . 'so it is not a lookup — the caller ends up on somebody\'s calendar.',
)]
class ProcessElevenLabsCalendarEventWebhookJob extends ProcessElevenLabsWebhookJob
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

        $rawDate = isset($payload['date']) ? (string) $payload['date'] : null;
        if ($rawDate === null || $rawDate === '') {
            $this->failedReturnHttpCode = 422;

            return ['status' => 422, 'message' => 'Date is required'];
        }

        $app = $this->receiver->app;
        $company = $this->receiver->company;
        $user = $this->resolveUser();
        $timezone = $company->get('timezone') ?? $company->timezone ?? 'UTC';

        $date = DateHelper::normalizeDate($rawDate, $timezone);
        if ($date === null) {
            $this->failedReturnHttpCode = 422;

            return [
                'status' => 422,
                'message' => 'Invalid date format. Expected Y-m-d (e.g. 2026-05-15). Received: ' . $rawDate,
            ];
        }

        $lead = $this->resolveLeadByPhone($phone);

        $this->updateLeadPeopleInfo($lead, $payload);

        $this->ensureEventDefaults($app, $user, $company);

        $startTime = isset($payload['start_time']) ? DateHelper::normalizeTime((string) $payload['start_time']) : null;
        $endTime = isset($payload['end_time']) ? DateHelper::normalizeTime((string) $payload['end_time']) : null;

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

    protected function ensureEventDefaults(
        AppInterface $app,
        UserInterface $user,
        CompanyInterface $company
    ): void {
        $hasDefaultCategory = EventCategory::fromApp($app)
            ->fromCompany($company)
            ->where('is_default', 1)
            ->exists();

        if (! $hasDefaultCategory) {
            new Setup($app, $user, $company)->run();
        }
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
}
