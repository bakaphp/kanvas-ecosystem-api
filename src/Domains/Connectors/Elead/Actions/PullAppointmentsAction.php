<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Elead\Actions;

use Baka\Users\Contracts\UserInterface;
use GuzzleHttp\Exception\ClientException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Kanvas\Connectors\Elead\Entities\SalesActivities;
use Kanvas\Connectors\Elead\Enums\ConfigurationEnum;
use Kanvas\Connectors\Elead\Enums\CustomFieldEnum;
use Kanvas\Connectors\Elead\Services\LeadUserService;
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
 * Mirrors the lead's open eLead appointments as Kanvas Events linked to the lead.
 *
 * eLead caps the history window at 31 days, so one call covers the next month; a Kanvas
 * event in that window whose activity no longer comes back open is re-read by id and either
 * moved (rescheduled past the window) or cancelled (closed in eLead).
 */
class PullAppointmentsAction
{
    public const int WINDOW_DAYS = 31;
    protected const string EVENT_TYPE_NAME = 'Appointment';

    protected ?int $durationMinutes = null;
    protected bool $eventSetupEnsured = false;

    public function __construct(
        protected Lead $lead
    ) {
    }

    /**
     * @return array<string, Event> keyed by eLead activity id
     */
    public function execute(): array
    {
        $opportunityId = (string) $this->lead->get(CustomFieldEnum::OPPORTUNITY_ID->value);

        if ($opportunityId === '') {
            return [];
        }

        $from = Carbon::now('UTC');
        $to = $from->copy()->addDays(self::WINDOW_DAYS);

        $activities = SalesActivities::getOpenActivitiesByOpportunityId(
            $this->lead->app,
            $this->lead->company,
            $opportunityId,
            $from,
            $to
        );

        return $this->apply($activities, $from, $to);
    }

    /**
     * @param SalesActivities[] $activities the open activities eLead returned for [$from, $to]
     * @return array<string, Event>
     */
    public function apply(array $activities, Carbon $from, Carbon $to): array
    {
        $typeNames = ConfigurationEnum::appointmentActivityTypes($this->lead->company);
        $synced = [];

        foreach ($activities as $activity) {
            if ($activity->id === null || $activity->dueDate() === null || ! $activity->isActivityType($typeNames)) {
                continue;
            }

            try {
                $synced[$activity->id] = $this->upsertEvent($activity);
            } catch (ValidationException $e) {
                // The slot validator refuses a second appointment overlapping one this lead already has.
                Log::warning('eLead appointment skipped', [
                    'lead_id' => $this->lead->getId(),
                    'activity_id' => $activity->id,
                    'reason' => $e->getMessage(),
                ]);
            }
        }

        $this->reconcileMissing($synced, $from, $to);

        return $synced;
    }

    protected function upsertEvent(SalesActivities $activity): Event
    {
        $start = $activity->dueDate();
        $end = $this->endFor($start);
        $event = $this->findEvent($activity->id);

        if ($event !== null) {
            $this->reschedule($event, $start, $end);

            return $event;
        }

        return $this->createEvent($activity, $start, $end);
    }

    protected function findEvent(string $activityId): ?Event
    {
        /** @var Event|null */
        return Event::getByCustomField(CustomFieldEnum::ACTIVITY_ID->value, $activityId, $this->lead->company);
    }

    protected function createEvent(SalesActivities $activity, Carbon $start, Carbon $end): Event
    {
        $app = $this->lead->app;
        $company = $this->lead->company;
        $user = LeadUserService::resolveByEleadUserId($company, $activity->assignedToUserId) ?? $this->lead->user;
        $this->ensureEventSetup($user);
        $type = EventType::fromApp($app)->fromCompany($company)->where('name', self::EVENT_TYPE_NAME)->first();

        $data = [
            'name' => $activity->name ?: self::EVENT_TYPE_NAME,
            'description' => $activity->activityType,
            'slug' => 'elead-appointment-' . $activity->id,
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

        if ($type !== null) {
            $data['type_id'] = $type->getId();
        }

        // Workflows stay off: the `created` rule would push this very appointment back to eLead.
        $event = new CreateEventAction(EventData::from($app, $user, $company, $data))
            ->disableWorkflow()
            ->withoutNotifications()
            ->execute();

        $event->set(CustomFieldEnum::ACTIVITY_ID->value, $activity->id);

        return $event;
    }

    /**
     * The event DTO resolves the company's default category, status, class and theme with
     * firstOrFail(), so a dealer onboarded before the Event setup existed cannot hold a single
     * appointment until it runs. Setup is firstOrCreate throughout, so running it here is safe.
     */
    protected function ensureEventSetup(UserInterface $user): void
    {
        if ($this->eventSetupEnsured) {
            return;
        }

        $app = $this->lead->app;
        $company = $this->lead->company;

        $hasDefaults = EventCategory::fromApp($app)->fromCompany($company)->where('is_default', 1)->exists()
            && EventType::fromApp($app)->fromCompany($company)->where('name', self::EVENT_TYPE_NAME)->exists();

        if (! $hasDefaults) {
            new Setup($app, $user, $company)->run();
        }

        $this->eventSetupEnsured = true;
    }

    protected function reschedule(Event $event, Carbon $start, Carbon $end): void
    {
        $version = $event->latestVersion();

        if ($version === null || ($version->start_at?->equalTo($start) && $version->end_at?->equalTo($end))) {
            return;
        }

        new UpdateEventAction($version, [
            'start_at' => $start,
            'end_at' => $end,
            'dates' => [$this->dateRow($start, $end)],
        ])->disableWorkflow()->withoutNotifications()->execute();
    }

    /**
     * @param array<string, Event> $synced
     */
    protected function reconcileMissing(array $synced, Carbon $from, Carbon $to): void
    {
        $events = $this->lead->events()
            ->whereHas('versions', fn ($query) => $query->whereBetween('start_at', [$from, $to]))
            ->get();

        foreach ($events as $event) {
            $activityId = (string) $event->get(CustomFieldEnum::ACTIVITY_ID->value);

            if ($activityId !== '' && ! isset($synced[$activityId]) && ! $event->isCancelled()) {
                $this->reconcile($event, $activityId);
            }
        }
    }

    /**
     * An open activity missing from the window was rescheduled past it; anything else was closed in eLead.
     */
    protected function reconcile(Event $event, string $activityId): void
    {
        $activity = $this->fetchActivity($activityId);

        if ($activity === null) {
            return;
        }

        $dueDate = $activity->dueDate();

        if ($dueDate !== null && $activity->completedDate === null && $activity->closedDate === null) {
            $this->reschedule($event, $dueDate, $this->endFor($dueDate));

            return;
        }

        $this->cancel($event);
    }

    protected function fetchActivity(string $activityId): ?SalesActivities
    {
        try {
            return SalesActivities::getById($this->lead->app, $this->lead->company, $activityId);
        } catch (ClientException $e) {
            return null;
        }
    }

    protected function cancel(Event $event): void
    {
        $version = $event->latestVersion();

        if ($version === null) {
            return;
        }

        try {
            new CancelEventAction($version)->disableWorkflow()->withoutNotifications()->execute();
        } catch (ValidationException $e) {
            // already cancelled between the status check and now
        }
    }

    protected function endFor(Carbon $start): Carbon
    {
        $this->durationMinutes ??= ConfigurationEnum::appointmentDurationMinutes($this->lead->company);

        return $start->copy()->addMinutes($this->durationMinutes);
    }

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
