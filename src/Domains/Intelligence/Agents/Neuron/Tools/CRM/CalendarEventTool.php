<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\CRM;

use Carbon\Carbon;
use Kanvas\Event\Events\Actions\CreateEventAction;
use Kanvas\Event\Events\DataTransferObject\Event as EventData;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesLeadForTool;
use NeuronAI\Tools\ArrayProperty;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;
use Throwable;

#[AgentTool(name: 'Calendar Event')]
class CalendarEventTool extends Tool
{
    use ResolvesLeadForTool;

    public function __construct()
    {
        parent::__construct(
            name: 'create_calendar_event',
            description: 'Create an internal calendar event (appointment / demo) for the lead owner in the Kanvas Event domain. '
                . 'Use this once the prospect has agreed on a specific date and time. '
                . 'The event will appear in subsequent availability checks so the same slot cannot be double-booked.',
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

            $event = new CreateEventAction($eventData)->disableWorkflow()->execute();
            $event->resources_id = $lead->getId();
            $event->resources_type = Lead::class;
            $event->saveQuietly();
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
