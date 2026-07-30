<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\CRM;

use Kanvas\Event\Events\Actions\CancelEventAction;
use Kanvas\Event\Events\Models\Event;
use Kanvas\Exceptions\ModelNotFoundException;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesLeadForTool;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;
use Throwable;

#[AgentTool(name: 'Cancel Calendar Event', category: 'crm')]
class CancelCalendarEventTool extends Tool
{
    use ResolvesLeadForTool;

    public function __construct()
    {
        parent::__construct(
            name: 'cancel_calendar_event',
            description: 'Cancel an EXISTING appointment for good (the prospect can no longer attend and is not '
                . 'rebooking right now). Pass the event_uuid from get_lead_ref appointments.upcoming[].uuid. '
                . 'This frees the time slot. To MOVE a meeting to another time, use reschedule_calendar_event instead.',
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
                description: 'The uuid of the appointment to cancel, from get_lead_ref appointments.upcoming[].uuid.',
                required: true,
            ),
        ];
    }

    public function __invoke(int $lead_id, string $event_uuid): array
    {
        $result = $this->resolveLeadOrError($lead_id);
        if (is_array($result)) {
            return $result;
        }
        $lead = $result;

        try {
            $event = Event::getByUuidFromCompanyApp($event_uuid, $lead->company, $lead->app);
        } catch (ModelNotFoundException) {
            return [
                'status' => 'error',
                'message' => "No appointment found with uuid {$event_uuid}. Use an event_uuid from get_lead_ref "
                    . 'appointments.upcoming — do not invent one.',
            ];
        }

        if ((int) $event->resources_id !== $lead->getId() || $event->resources_type !== Lead::class) {
            return [
                'status' => 'error',
                'message' => "Appointment {$event_uuid} does not belong to lead {$lead_id}. Refusing to cancel it.",
            ];
        }

        $version = $event->versions()->where('is_deleted', 0)->orderByDesc('id')->first();
        if ($version === null) {
            return [
                'status' => 'error',
                'message' => "Appointment {$event_uuid} has no version to cancel.",
            ];
        }

        try {
            new CancelEventAction($version)->execute();

            // Free the slot in availability — getScheduled reads dates regardless of status.
            $event->versions()->get()->each(fn ($v) => $v->dates()->delete());
        } catch (Throwable $e) {
            return [
                'status' => 'error',
                'message' => 'Failed to cancel the appointment: ' . $e->getMessage(),
            ];
        }

        return [
            'status' => 'success',
            'lead_id' => $lead_id,
            'event' => [
                'id' => $event->getId(),
                'uuid' => $event->uuid,
                'name' => $event->name,
                'cancelled' => true,
            ],
        ];
    }
}
