<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\CRM;

use Carbon\Carbon;
use Kanvas\Connectors\Google\Actions\CreateGoogleCalendarMeetingAction;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesLeadForTool;
use NeuronAI\Tools\ArrayProperty;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;
use Throwable;

#[AgentTool(name: 'Google Calendar')]
class GoogleCalendarTool extends Tool
{
    use ResolvesLeadForTool;

    public function __construct()
    {
        parent::__construct(
            name: 'create_google_calendar_meeting',
            description: 'Create a Google Calendar event with a Google Meet link and invite the given attendee emails. '
                . 'Use this when the lead agrees to schedule a meeting and you have the date, time and the list '
                . 'of participant emails.',
        );
    }

    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'lead_id',
                type: PropertyType::INTEGER,
                description: 'The ID of the lead this meeting is for. Used to resolve company timezone.',
                required: true,
            ),
            new ToolProperty(
                name: 'title',
                type: PropertyType::STRING,
                description: 'The meeting title (e.g., "Vehicle Demo Call with John Doe").',
                required: true,
            ),
            new ArrayProperty(
                name: 'attendee_emails',
                description: 'List of attendee emails to invite to the meeting.',
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
                description: 'Meeting start datetime in the company timezone, format YYYY-MM-DD HH:MM (24h).',
                required: true,
            ),
            new ToolProperty(
                name: 'end_datetime',
                type: PropertyType::STRING,
                description: 'Meeting end datetime in the company timezone, format YYYY-MM-DD HH:MM (24h).',
                required: true,
            ),
            new ToolProperty(
                name: 'description',
                type: PropertyType::STRING,
                description: 'Optional meeting description / agenda.',
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
    ): array {
        $result = $this->resolveLeadOrError($lead_id);
        if (is_array($result)) {
            return $result;
        }
        $lead = $result;
        $timezone = $lead->company->timezone ?? 'UTC';

        try {
            $start = Carbon::parse($start_datetime, $timezone);
            $end = Carbon::parse($end_datetime, $timezone);
        } catch (Throwable $e) {
            return [
                'status' => 'error',
                'message' => 'Invalid start_datetime or end_datetime. Use format YYYY-MM-DD HH:MM. Error: ' . $e->getMessage(),
            ];
        }

        if ($end->lessThanOrEqualTo($start)) {
            return [
                'status' => 'error',
                'message' => 'end_datetime must be after start_datetime.',
            ];
        }

        try {
            $meeting = new CreateGoogleCalendarMeetingAction(
                company: $lead->company,
                name: $title,
                attendeeEmails: $attendee_emails,
                startDateTime: $start,
                endDateTime: $end,
                description: $description,
                withMeetLink: true,
            )->execute();
        } catch (Throwable $e) {
            return [
                'status' => 'error',
                'message' => 'Failed to create Google Calendar meeting: ' . $e->getMessage(),
            ];
        }

        return [
            'status' => 'success',
            'lead_id' => $lead_id,
            'meeting' => $meeting,
        ];
    }
}
