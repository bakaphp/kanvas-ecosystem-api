<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\CRM;

use Carbon\Carbon;
use Kanvas\Event\Events\Actions\CreateEventAction;
use Kanvas\Event\Events\DataTransferObject\Event as EventData;
use Kanvas\Event\Events\Models\EventCategory;
use Kanvas\Event\Events\Models\EventClass;
use Kanvas\Event\Events\Models\EventStatus;
use Kanvas\Event\Events\Models\EventType;
use Kanvas\Event\Themes\Models\Theme;
use Kanvas\Event\Themes\Models\ThemeArea;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesLeadForTool;
use Kanvas\Workflow\Enums\WorkflowEnum;
use NeuronAI\Tools\ArrayProperty;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;
use Throwable;

#[AgentTool(name: 'Calendar Event', category: 'crm')]
class CalendarEventTool extends Tool
{
    use ResolvesLeadForTool;

    public function __construct()
    {
        parent::__construct(
            name: 'create_calendar_event',
            description: 'Create a NEW internal calendar event (appointment / demo) for the lead owner in the Kanvas Event domain. '
                . 'Call get_event_configuration first and pass all six company-scoped configuration IDs returned by it. '
                . 'Use this once the prospect has agreed on a specific date and time. '
                . 'The event will appear in subsequent availability checks so the same slot cannot be double-booked. '
                . 'IMPORTANT: if the lead ALREADY has an upcoming appointment (see get_lead_ref appointments.upcoming), '
                . 'do NOT call this to move it — use reschedule_calendar_event (to change the time) or '
                . 'cancel_calendar_event (to cancel). Calling create for an existing meeting double-books the lead.',
        );
    }

    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'lead_id',
                type: PropertyType::INTEGER,
                description: 'The ID of the lead the event is for. Resolves company, owner, and timezone.',
                required: true,
            ),
            new ToolProperty(
                name: 'title',
                type: PropertyType::STRING,
                description: 'The event title (e.g., "Product demo with John Doe").',
                required: true,
            ),
            new ArrayProperty(
                name: 'attendee_emails',
                description: 'List of attendee emails for the event (stored on the event description for now).',
                required: true,
                items: new ToolProperty(
                    name: 'email',
                    type: PropertyType::STRING,
                    description: 'A single attendee email address.',
                    required: true,
                ),
            ),
            new ToolProperty(
                name: 'start_datetime',
                type: PropertyType::STRING,
                description: 'Start datetime in the company timezone, format YYYY-MM-DD HH:MM (24h).',
                required: true,
            ),
            new ToolProperty(
                name: 'end_datetime',
                type: PropertyType::STRING,
                description: 'End datetime in the company timezone, format YYYY-MM-DD HH:MM (24h).',
                required: true,
            ),
            new ToolProperty(
                name: 'description',
                type: PropertyType::STRING,
                description: 'Optional event description / agenda.',
                required: false,
            ),
            new ToolProperty(
                name: 'meeting_link',
                type: PropertyType::STRING,
                description: 'Optional meeting URL (Zoom / Meet / etc.) to attach to the event.',
                required: false,
            ),
            new ToolProperty(
                name: 'theme_id',
                type: PropertyType::INTEGER,
                description: 'Theme ID returned by get_event_configuration.',
                required: true,
            ),
            new ToolProperty(
                name: 'theme_area_id',
                type: PropertyType::INTEGER,
                description: 'Theme area ID returned by get_event_configuration.',
                required: true,
            ),
            new ToolProperty(
                name: 'status_id',
                type: PropertyType::INTEGER,
                description: 'Event status ID returned by get_event_configuration.',
                required: true,
            ),
            new ToolProperty(
                name: 'type_id',
                type: PropertyType::INTEGER,
                description: 'Event type ID returned by get_event_configuration.',
                required: true,
            ),
            new ToolProperty(
                name: 'class_id',
                type: PropertyType::INTEGER,
                description: 'Event class ID returned by get_event_configuration.',
                required: true,
            ),
            new ToolProperty(
                name: 'category_id',
                type: PropertyType::INTEGER,
                description: 'Event category ID returned by get_event_configuration.',
                required: true,
            ),
        ];
    }

    public function __invoke(
        int $lead_id,
        string $title,
        array $attendee_emails,
        string $start_datetime,
        string $end_datetime,
        ?string $description = null,
        ?string $meeting_link = null,
        ?int $theme_id = null,
        ?int $theme_area_id = null,
        ?int $status_id = null,
        ?int $type_id = null,
        ?int $class_id = null,
        ?int $category_id = null,
    ): array {
        $result = $this->resolveLeadOrError($lead_id);
        if (is_array($result)) {
            return $result;
        }
        $lead = $result;

        $company = $lead->company;
        $owner = $lead->owner;

        if ($owner === null) {
            return [
                'status' => 'error',
                'message' => 'Lead has no owner. Cannot create an event without a salesperson assigned.',
            ];
        }

        $tz = $company->timezone ?? 'UTC';

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

        if (! $start->isSameDay($end)) {
            return [
                'status' => 'error',
                'message' => 'Appointments must start and end on the same local calendar date.',
            ];
        }

        $configurationIds = [
            'theme_id' => $theme_id,
            'theme_area_id' => $theme_area_id,
            'status_id' => $status_id,
            'type_id' => $type_id,
            'class_id' => $class_id,
            'category_id' => $category_id,
        ];
        $providedConfigurationIds = array_filter($configurationIds, static fn (?int $id): bool => $id !== null);

        // Keep the legacy all-default path temporarily, but never allow a partially specified configuration.
        if ($providedConfigurationIds !== [] && count($providedConfigurationIds) !== count($configurationIds)) {
            return [
                'status' => 'error',
                'message' => 'Incomplete Event configuration. Call get_event_configuration and pass theme_id, '
                    . 'theme_area_id, status_id, type_id, class_id, and category_id.',
            ];
        }

        if ($providedConfigurationIds !== []) {
            $models = [
                'theme_id' => Theme::class,
                'theme_area_id' => ThemeArea::class,
                'status_id' => EventStatus::class,
                'type_id' => EventType::class,
                'class_id' => EventClass::class,
                'category_id' => EventCategory::class,
            ];

            foreach ($models as $field => $modelClass) {
                $exists = $modelClass::query()
                    ->where('id', $configurationIds[$field])
                    ->where('apps_id', $lead->apps_id)
                    ->where('companies_id', $company->getId())
                    ->where('is_deleted', 0)
                    ->exists();

                if (! $exists) {
                    return [
                        'status' => 'error',
                        'message' => "Invalid {$field}. Use an ID returned by get_event_configuration for this lead.",
                    ];
                }
            }

            $category = EventCategory::getById((int) $category_id);
            if ((int) $category->event_type_id !== $type_id || (int) $category->event_class_id !== $class_id) {
                return [
                    'status' => 'error',
                    'message' => 'The selected category does not belong to the selected event type and class. '
                        . 'Use the event_type_id and event_class_id returned with the category.',
                ];
            }
        }

        $attendeeBlock = $attendee_emails === [] ? '' : "\nAttendees: " . implode(', ', $attendee_emails);
        $fullDescription = trim(($description ?? '') . $attendeeBlock);

        try {
            $eventData = EventData::from(
                $lead->app,
                $owner,
                $company,
                [
                    'name' => $title,
                    'description' => $fullDescription !== '' ? $fullDescription : null,
                    'meeting_link' => $meeting_link,
                    'theme_id' => $theme_id,
                    'theme_area_id' => $theme_area_id,
                    'status_id' => $status_id,
                    'type_id' => $type_id,
                    'class_id' => $class_id,
                    'category_id' => $category_id,
                    'dates' => [
                        [
                            'date' => $start->format('Y-m-d'),
                            'start_time' => $start->format('H:i'),
                            'end_time' => $end->format('H:i'),
                        ],
                    ],
                    'resources' => [
                        [
                            'resources_id' => $lead->getId(),
                            'resources_type' => 'lead',
                        ],
                    ],
                ]
            );

            $event = new CreateEventAction($eventData, [
                'google_calendar' => [
                    'attendee_emails' => array_values(array_unique($attendee_emails)),
                ],
            ])->disableWorkflow()->execute();
            $event->resources_id = $lead->getId();
            $event->resources_type = Lead::class;
            $event->saveQuietly();
            $event->fireWorkflow(
                WorkflowEnum::CREATED->value,
                true,
                ['app' => $lead->app, 'company' => $company],
            );
        } catch (Throwable $e) {
            return [
                'status' => 'error',
                'message' => 'Failed to create event: ' . $e->getMessage(),
                'hint' => 'The Event domain may need default Theme/ThemeArea/EventStatus/EventType/EventCategory/EventClass rows configured for this company.',
            ];
        }

        return [
            'status' => 'success',
            'lead_id' => $lead_id,
            'event' => [
                'id' => $event->getId(),
                'uuid' => $event->uuid,
                'name' => $event->name,
                'start_local' => $start->toIso8601String(),
                'end_local' => $end->toIso8601String(),
                'company_timezone' => $tz,
                'owner_user_id' => $owner->getId(),
                'attendees' => $attendee_emails,
                'meeting_link' => $meeting_link,
            ],
        ];
    }
}
