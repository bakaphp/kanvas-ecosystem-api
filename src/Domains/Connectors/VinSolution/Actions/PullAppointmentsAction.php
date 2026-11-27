<?php

declare(strict_types=1);

namespace Kanvas\Connectors\VinSolution\Actions;

use Baka\Support\Str;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Kanvas\Connectors\VinSolution\Appointments\AppointmentClient;
use Kanvas\Connectors\VinSolution\Appointments\AppointmentLeadContext;
use Kanvas\Connectors\VinSolution\Enums\ConfigurationEnum;
use Kanvas\Connectors\VinSolution\Enums\CustomFieldEnum;
use Kanvas\Connectors\VinSolution\Session\SessionTokenService;
use Kanvas\Event\Events\Actions\CancelEventAction;
use Kanvas\Event\Events\Actions\CreateEventAction;
use Kanvas\Event\Events\Actions\UpdateEventAction;
use Kanvas\Event\Events\DataTransferObject\Event as EventData;
use Kanvas\Event\Events\Models\Event;
use Kanvas\Event\Events\Models\EventCategory;
use Kanvas\Event\Events\Models\EventType;
use Kanvas\Event\Support\Setup;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Guild\Leads\Models\Lead;

/**
 * Mirrors the lead's VinSolutions appointments as Kanvas Events linked to the lead, which is where the
 * agent reads them. The list carries each appointment's status, so a cancellation is read off the list
 * itself. It also requires a UserId: an appointment assigned to another rep is not pulled.
 */
class PullAppointmentsAction
{
    private const EVENT_TYPE_NAME = 'Appointment';

    public function __construct(
        protected Lead $lead,
        protected ?AppointmentClient $client = null,
        protected ?SessionTokenService $session = null,
    ) {
    }

    /**
     * @return array<string, Event> keyed by VinSolutions appointment id
     */
    public function execute(): array
    {
        $company = $this->lead->company;
        $context = AppointmentLeadContext::fromLead($this->lead);
        $cookies = Str::trimToNull((string) $company->get(ConfigurationEnum::SESSION_COOKIES->value));

        // Runs on every lead sync, so a company with no session stored is skipped without a log line.
        if ($cookies === null
            || $context->dealerId === null
            || $context->assignedUserId === null
            || $context->customerId === null) {
            return [];
        }

        $client = $this->client ?? new AppointmentClient();
        $session = $this->session ?? new SessionTokenService($this->lead->app, $company);

        try {
            $token = $session->getAccessToken();
        } catch (ValidationException) {
            // A dead session stays dead until its cookies are replaced: warn, don't report every sync.
            Log::warning('VinSolutions appointment sync skipped: session expired', [
                'company_id' => $company->getId(),
                'lead_id' => $this->lead->getId(),
            ]);

            return [];
        }

        $items = $client->listAppointments(
            $token,
            (int) $context->dealerId,
            (int) $context->assignedUserId,
            (int) $context->customerId
        );

        if ($items === []) {
            return [];
        }

        $user = $context->assignedUser;
        $this->ensureEventSetup($user);
        $synced = [];

        foreach ($items as $item) {
            $id = (string) ($item['id'] ?? '');

            if ($id === '' || empty($item['startTimeUtc'])) {
                continue;
            }

            try {
                $event = $this->applyItem($item, $id, $user);
            } catch (ValidationException $e) {
                Log::warning('VinSolutions appointment skipped', [
                    'lead_id' => $this->lead->getId(),
                    'appointment_id' => $id,
                    'reason' => $e->getMessage(),
                ]);

                continue;
            }

            if ($event !== null) {
                $synced[$id] = $event;
            }
        }

        return $synced;
    }

    protected function applyItem(array $item, string $id, UserInterface $user): ?Event
    {
        $event = $this->findEvent($id);

        if (strtoupper((string) ($item['status'] ?? 'ACTIVE')) === 'CANCELLED') {
            if ($event !== null && ! $event->isCancelled()) {
                $this->cancel($event);
            }

            return $event;
        }

        $start = Carbon::parse($item['startTimeUtc'])->utc();
        $end = empty($item['endTimeUtc'])
            ? $start->copy()->addMinutes(ConfigurationEnum::appointmentDurationMinutes($this->lead->company))
            : Carbon::parse($item['endTimeUtc'])->utc();

        if ($event !== null) {
            $this->reschedule($event, $start, $end);

            return $event;
        }

        return $this->createEvent(
            $item,
            $id,
            $start,
            $end,
            $user
        );
    }

    /**
     * By the stored appointment id, not through the lead's `event_resources`: an Event booked from Kanvas
     * can carry the lead only as its primary resource, and would be mirrored a second time.
     */
    protected function findEvent(string $appointmentId): ?Event
    {
        /** @var Event|null */
        return Event::getByCustomFieldTransactionSafe(
            CustomFieldEnum::APPOINTMENT_ID->value,
            $appointmentId,
            $this->lead->company
        );
    }

    protected function createEvent(
        array $item,
        string $id,
        Carbon $start,
        Carbon $end,
        UserInterface $user
    ): Event {
        $app = $this->lead->app;
        $company = $this->lead->company;

        $data = [
            'name' => Str::trimToNull($item['description'] ?? null) ?? self::EVENT_TYPE_NAME,
            'description' => (string) ($item['purpose'] ?? self::EVENT_TYPE_NAME),
            'slug' => 'vinsolution-appointment-' . $id,
            'resource' => $this->lead,
            'dates' => [$this->dateRow($start, $end)],
            'start_at' => $start->toIso8601String(),
            'end_at' => $end->toIso8601String(),
            'resources' => [
                [
                    'resources_id' => $this->lead->getId(),
                    'resources_type' => 'lead',
                ],
            ],
        ];

        $type = EventType::fromApp($app)
            ->fromCompany($company)
            ->where('name', self::EVENT_TYPE_NAME)
            ->first();

        if ($type !== null) {
            $data['type_id'] = $type->getId();
        }

        // Workflows off: the Event `created` rule would push this very appointment back to VinSolutions.
        $event = new CreateEventAction(EventData::from($app, $user, $company, $data))
            ->disableWorkflow()
            ->withoutNotifications()
            ->execute();

        $event->set(CustomFieldEnum::APPOINTMENT_ID->value, $id);

        return $event;
    }

    protected function reschedule(Event $event, Carbon $start, Carbon $end): void
    {
        $version = $event->latestVersion();
        $unchanged = $version?->start_at?->equalTo($start) && $version->end_at?->equalTo($end);

        if ($version === null || $unchanged) {
            return;
        }

        new UpdateEventAction(
            $version,
            [
                'start_at' => $start,
                'end_at' => $end,
                'dates' => [$this->dateRow($start, $end)],
            ]
        )->disableWorkflow()->withoutNotifications()->execute();
    }

    protected function cancel(Event $event): void
    {
        $version = $event->latestVersion();

        if ($version === null) {
            return;
        }

        try {
            new CancelEventAction($version)->disableWorkflow()->withoutNotifications()->execute();
        } catch (ValidationException) {
            // already cancelled between the status check and now
        }
    }

    /**
     * Creating an Event needs the company's default category, status and class, which a dealer that
     * never used Events does not have.
     */
    protected function ensureEventSetup(UserInterface $user): void
    {
        $app = $this->lead->app;
        $company = $this->lead->company;

        if (! EventCategory::fromApp($app)->fromCompany($company)->where('is_default', 1)->exists()) {
            new Setup($app, $user, $company)->run();
        }
    }

    /**
     * @return array{date: string, start_time: string, end_time: string}
     */
    protected function dateRow(Carbon $start, Carbon $end): array
    {
        $timezone = $this->lead->company->getTimezone() ?? 'UTC';
        $localStart = $start->copy()->setTimezone($timezone);
        $localEnd = $end->copy()->setTimezone($timezone);

        return [
            'date' => $localStart->toDateString(),
            'start_time' => $localStart->format('H:i'),
            'end_time' => $localEnd->format('H:i'),
        ];
    }
}
