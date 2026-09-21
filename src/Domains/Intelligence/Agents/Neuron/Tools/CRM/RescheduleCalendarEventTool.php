<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\CRM;

use Baka\Support\Str;
use Carbon\Carbon;
use Kanvas\Event\Events\Actions\UpdateEventAction;
use Kanvas\Event\Events\Enums\EventStatusEnum;
use Kanvas\Event\Events\Models\Event;
use Kanvas\Exceptions\ModelNotFoundException;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Enums\ToolOutcomeEnum;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\GuardsOwnerCalendarForTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesLeadForTool;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;
use Throwable;

#[AgentTool(name: 'Reschedule Calendar Event', category: 'crm')]
class RescheduleCalendarEventTool extends Tool
{
    use GuardsOwnerCalendarForTool;
    use ResolvesLeadForTool;

    public function __construct()
    {
        parent::__construct(
            name: 'reschedule_calendar_event',
            description: 'Move an EXISTING appointment to a new date/time. Use this — NOT create_calendar_event — '
                . 'whenever the prospect wants to change a meeting that is already on the calendar. '
                . 'Pass the event_uuid from get_lead_ref appointments.upcoming[].uuid. The previous slot is '
                . 'freed automatically (the appointment is moved in place, not duplicated). '
                . 'Check get_user_availability first so the new time is real. '
                . 'When the result has lead_notified: true the lead was already emailed the new time — do not send your own.',
        );
    }

    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'lead_id',
                type: PropertyType::INTEGER,
                description: 'The ID of the lead the appointment belongs to.',
                required: true,
            ),
            new ToolProperty(
                name: 'event_uuid',
                type: PropertyType::STRING,
                description: 'The uuid of the appointment to move, taken from get_lead_ref appointments.upcoming[].uuid.',
                required: true,
            ),
            new ToolProperty(
                name: 'start_datetime',
                type: PropertyType::STRING,
                description: 'New start datetime in the company timezone, format YYYY-MM-DD HH:MM (24h).',
                required: true,
            ),
            new ToolProperty(
                name: 'end_datetime',
                type: PropertyType::STRING,
                description: 'New end datetime in the company timezone, format YYYY-MM-DD HH:MM (24h).',
                required: true,
            ),
            new ToolProperty(
                name: 'title',
                type: PropertyType::STRING,
                description: 'Optional new title. Omit to keep the current one.',
                required: false,
            ),
            new ToolProperty(
                name: 'description',
                type: PropertyType::STRING,
                description: 'Optional new description / agenda. Omit to keep the current one.',
                required: false,
            ),
        ];
    }

    public function __invoke(
        int $lead_id,
        string $event_uuid,
        string $start_datetime,
        string $end_datetime,
        ?string $title = null,
        ?string $description = null,
    ): array {
        $result = $this->resolveLeadOrError($lead_id);
        if (is_array($result)) {
            return $result;
        }
        $lead = $result;

        $company = $lead->company;
        $tz = $company->timezone ?? 'UTC';

        try {
            $event = Event::getByUuidFromCompanyApp($event_uuid, $company, $lead->app);
        } catch (ModelNotFoundException) {
            return [
                'status' => 'error',
                'message' => "No appointment found with uuid {$event_uuid}. Call get_lead_ref and use an "
                    . 'event_uuid from appointments.upcoming — do not invent one.',
            ];
        }

        if ((int) $event->resources_id !== $lead->getId() || $event->resources_type !== Lead::class) {
            return [
                'status' => 'error',
                'message' => "Appointment {$event_uuid} does not belong to lead {$lead_id}. Refusing to move it.",
            ];
        }

        try {
            $start = Carbon::parse($start_datetime, $tz);
            $end = Carbon::parse($end_datetime, $tz);
        } catch (Throwable $e) {
            return [
                'status' => 'error',
                'message' => 'Invalid start_datetime or end_datetime. Use YYYY-MM-DD HH:MM. ' . $e->getMessage(),
            ];
        }

        if ($end->lte($start)) {
            return [
                'status' => 'error',
                'message' => 'end_datetime must be after start_datetime.',
            ];
        }

        if ($event->eventStatus?->name === EventStatusEnum::CANCELLED->value) {
            return $this->withOutcome(ToolOutcomeEnum::DENIED, [
                'status' => 'error',
                'message' => "Appointment {$event_uuid} is cancelled, so it was not moved. "
                    . 'Book a new one with create_calendar_event instead.',
            ]);
        }

        // get_lead_ref treats the latest version as the live appointment; move that one.
        $version = $event->versions()->where('is_deleted', 0)->orderByDesc('id')->first();
        if ($version === null) {
            return [
                'status' => 'error',
                'message' => "Appointment {$event_uuid} has no schedulable version to move.",
            ];
        }

        return $this->writeIfOwnerIsFree(
            lead: $lead,
            // The version's user, not the lead's current owner: addDates() writes the moved slot there.
            owner: $version->user,
            start: $start,
            end: $end,
            write: function () use ($lead, $event, $version, $start, $end, $tz, $title, $description): array {
                $title = Str::trimToNull($title);
                $description = Str::trimToNull($description);

                try {
                    // Clear stale dates left on older versions (e.g. by an earlier create-as-reschedule)
                    // so availability frees the previous slot and the event holds exactly one date.
                    $event->versions()
                        ->where('id', '!=', $version->getId())
                        ->get()
                        ->each(fn ($staleVersion) => $staleVersion->dates()->delete());

                    // Renamed here rather than through UpdateEventAction: that action regenerates the
                    // event slug from the name alone, which collides with any other event that has
                    // the same title.
                    if ($title !== null) {
                        $event->update(['name' => $title]);
                        $version->update(['name' => $title]);
                    }

                    $updateData = [
                        'start_at' => $start->copy()->utc(),
                        'end_at' => $end->copy()->utc(),
                        'dates' => [
                            [
                                'date' => $start->format('Y-m-d'),
                                'start_time' => $start->format('H:i'),
                                'end_time' => $end->format('H:i'),
                            ],
                        ],
                    ];
                    if ($description !== null) {
                        $updateData['description'] = $description;
                    }

                    new UpdateEventAction($version, $updateData)->execute();
                } catch (ValidationException $e) {
                    return $this->withOutcome(ToolOutcomeEnum::INVALID_ARGS, [
                        'status' => 'error',
                        'message' => 'The appointment was not moved: ' . $e->getMessage(),
                    ]);
                } catch (Throwable $e) {
                    report($e);

                    return $this->withOutcome(ToolOutcomeEnum::PROVIDER_ERROR, [
                        'status' => 'error',
                        'message' => 'Failed to reschedule the appointment: ' . $e->getMessage(),
                    ]);
                }

                return $this->appointmentSaved([
                    'status' => 'success',
                    'lead_id' => $lead->getId(),
                    'event' => [
                        'id' => $event->getId(),
                        'uuid' => $event->uuid,
                        'name' => $title ?? $event->name,
                        'start_local' => $start->toIso8601String(),
                        'end_local' => $end->toIso8601String(),
                        'company_timezone' => $tz,
                        'moved' => true,
                    ],
                ], $version);
            },
            excludeEventId: $event->getId(),
        );
    }
}
